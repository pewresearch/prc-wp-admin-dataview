import { filtersEqual } from '../components/saved-filters/helpers.js';
import { ensureFilteredFieldsVisible } from '../utils/ensure-filtered-fields-visible.js';

const DENSITIES = new Set(['compact', 'balanced', 'comfortable']);
const LAYOUTS = {
	table: { persistPreviewSize: false, showMedia: false },
	grid: { persistPreviewSize: true, showMedia: true },
	list: { persistPreviewSize: false, showMedia: true },
};
const MODES = new Set(Object.keys(LAYOUTS));
const ROWS_PER_PAGE = [10, 20, 50, 100];
const FILTER_OPERATORS = new Set([
	'is',
	'isNot',
	'isAny',
	'isNone',
	'isAll',
	'isNotAll',
	'lessThan',
	'greaterThan',
	'lessThanOrEqual',
	'greaterThanOrEqual',
	'contains',
	'notContains',
	'startsWith',
	'before',
	'after',
	'beforeInc',
	'afterInc',
	'on',
	'notOn',
	'between',
]);

/**
 * Parse a localized or stored appearance document against current fields.
 *
 * @param {*}      raw          Raw wire document.
 * @param {Object} capabilities Current field capabilities.
 * @return {Object|null} Canonical sparse document.
 */
export function parseAppearanceDocument(raw, capabilities) {
	if (
		!isPlainObject(raw) ||
		raw.version !== 1 ||
		!isPlainObject(raw.overrides)
	) {
		return null;
	}

	const source = raw.overrides;
	const overrides = {};

	if (MODES.has(source.mode)) {
		overrides.mode = source.mode;
	}
	if (ROWS_PER_PAGE.includes(source.rowsPerPage)) {
		overrides.rowsPerPage = source.rowsPerPage;
	}

	const ordering = parseOrdering(source.ordering, capabilities);
	if (ordering) {
		overrides.ordering = ordering;
	}

	if (Array.isArray(source.visibleFieldIds)) {
		const visibleFieldIds = filterKnownIds(
			source.visibleFieldIds,
			capabilities
		);
		if (source.visibleFieldIds.length === 0 || visibleFieldIds.length > 0) {
			overrides.visibleFieldIds = visibleFieldIds;
		}
	}

	if (Array.isArray(source.filters)) {
		const filters = parseFilters(source.filters, capabilities);
		if (source.filters.length === 0 || filters.length > 0) {
			overrides.filters = filters;
		}
	}

	if (typeof source.showLevels === 'boolean') {
		overrides.showLevels = source.showLevels;
	}

	Object.assign(overrides, parseLayoutOverrides(source));

	if (Object.keys(overrides).length === 0) {
		return null;
	}

	return { version: 1, overrides };
}

/**
 * Create the state used by the appearance persistence hook.
 *
 * @param {Object}  options                Session options.
 * @param {Object}  options.defaultsView   Current provider default view.
 * @param {Object}  options.capabilities   Current field capabilities.
 * @param {*}       options.stored         Localized appearance document.
 * @param {boolean} options.urlOwnsFilters Whether the URL supplied this visit's filters.
 * @param {Array}   options.defaultFilters Provider baseline filters (not URL-hydrated).
 * @return {Object} Appearance session.
 */
export function createAppearanceSession({
	defaultsView,
	capabilities,
	stored,
	urlOwnsFilters = false,
	defaultFilters = [],
}) {
	const defaults = appearanceFromView(defaultsView, capabilities);
	const document = parseAppearanceDocument(stored, capabilities);
	const durable = applyDocument(defaults, document);
	const projected = projectRestoredView(defaultsView, durable);
	const view = ensureFilteredFieldsVisible(
		!urlOwnsFilters && durable.filters !== undefined
			? {
					...projected,
					filters: overlayClassicStatus(
						durable.filters,
						projected.filters
					),
				}
			: projected
	);

	return {
		view,
		durable,
		autoVisibleFieldIds: getAutoVisibleFieldIds(
			view,
			durable.visibleFieldIds
		),
		defaults,
		defaultFilters: Array.isArray(defaultFilters) ? defaultFilters : [],
		capabilities,
		document,
	};
}

/**
 * Apply a DataViews view change and return a save only for a changed document.
 *
 * @param {Object}          session           Current appearance session.
 * @param {Object|Function} nextViewOrUpdater Next view or functional updater.
 * @return {{session: Object, save?: Object|null}} Transition result.
 */
export function transitionAppearanceSession(session, nextViewOrUpdater) {
	const nextView =
		typeof nextViewOrUpdater === 'function'
			? nextViewOrUpdater(session.view)
			: nextViewOrUpdater;
	const mode = MODES.has(nextView.type)
		? nextView.type
		: session.durable.mode;
	const durable = {
		...session.durable,
		mode,
		rowsPerPage: clampRowsPerPage(nextView.perPage),
		ordering:
			parseOrdering(
				nextView.sort
					? {
							fieldId: nextView.sort.field,
							direction: nextView.sort.direction,
						}
					: null,
				session.capabilities
			) || session.defaults.ordering,
		showLevels: Boolean(nextView.showLevels),
		...copyLayouts(session.durable),
	};

	if (!arraysEqual(nextView.fields, session.view.fields)) {
		const fields = Array.isArray(nextView.fields) ? nextView.fields : [];
		durable.visibleFieldIds = filterKnownIds(
			fields.filter(
				(fieldId) => !session.autoVisibleFieldIds.has(fieldId)
			),
			session.capabilities
		);
	}

	if (!filtersEqual(nextView.filters, session.view.filters)) {
		durable.filters = Array.isArray(nextView.filters)
			? [...nextView.filters]
			: [];
	}

	if (mode === session.view.type) {
		durable[mode] = {
			...durable[mode],
			...parseLayout(nextView.layout, LAYOUTS[mode].persistPreviewSize),
		};
	}

	const document = encodeDocument(
		durable,
		session.defaults,
		session.defaultFilters
	);
	const projected = projectView(nextView, durable);
	const view = ensureFilteredFieldsVisible(projected);
	const nextSession = {
		...session,
		view,
		durable,
		autoVisibleFieldIds: getAutoVisibleFieldIds(
			view,
			durable.visibleFieldIds
		),
		document,
	};

	if (appearanceDocumentsEqual(document, session.document)) {
		return { session: nextSession };
	}

	return { session: nextSession, save: document };
}

/**
 * Compare canonical appearance documents by value.
 *
 * @param {*} a First document.
 * @param {*} b Second document.
 * @return {boolean} Whether both documents have equal values.
 */
export function appearanceDocumentsEqual(a, b) {
	if (Object.is(a, b)) {
		return true;
	}
	if (Array.isArray(a) || Array.isArray(b)) {
		return (
			Array.isArray(a) &&
			Array.isArray(b) &&
			a.length === b.length &&
			a.every((value, index) => appearanceDocumentsEqual(value, b[index]))
		);
	}
	if (!isPlainObject(a) || !isPlainObject(b)) {
		return false;
	}

	const aKeys = Object.keys(a);
	const bKeys = Object.keys(b);
	return (
		aKeys.length === bKeys.length &&
		aKeys.every(
			(key) =>
				Object.prototype.hasOwnProperty.call(b, key) &&
				appearanceDocumentsEqual(a[key], b[key])
		)
	);
}

function appearanceFromView(view, capabilities) {
	const mode = MODES.has(view.type) ? view.type : 'table';
	const appearance = {
		mode,
		rowsPerPage: clampRowsPerPage(view.perPage),
		ordering: parseOrdering(
			view.sort
				? {
						fieldId: view.sort.field,
						direction: view.sort.direction,
					}
				: null,
			capabilities
		),
		visibleFieldIds: filterKnownIds(view.fields || [], capabilities),
		showLevels: Boolean(view.showLevels),
		...emptyLayouts(),
	};

	appearance[mode] = parseLayout(
		view.layout,
		LAYOUTS[mode].persistPreviewSize
	);
	return appearance;
}

function applyDocument(defaults, document) {
	const durable = {
		...defaults,
		ordering: defaults.ordering ? { ...defaults.ordering } : null,
		visibleFieldIds: [...defaults.visibleFieldIds],
		...copyLayouts(defaults),
		filters: undefined,
	};
	if (!document) {
		return durable;
	}

	const overrides = document.overrides;
	if (overrides.mode !== undefined) {
		durable.mode = overrides.mode;
	}
	if (overrides.rowsPerPage !== undefined) {
		durable.rowsPerPage = overrides.rowsPerPage;
	}
	if (overrides.ordering !== undefined) {
		durable.ordering = { ...overrides.ordering };
	}
	if (overrides.visibleFieldIds !== undefined) {
		durable.visibleFieldIds = [...overrides.visibleFieldIds];
	}
	if (overrides.showLevels !== undefined) {
		durable.showLevels = overrides.showLevels;
	}
	for (const layoutMode of MODES) {
		if (overrides[layoutMode]) {
			durable[layoutMode] = {
				...durable[layoutMode],
				...overrides[layoutMode],
			};
		}
	}
	if (overrides.filters !== undefined) {
		durable.filters = [...overrides.filters];
	}

	return durable;
}

function overlayClassicStatus(storedFilters, projectedFilters) {
	const classicStatus = (projectedFilters || []).find(
		(filter) => filter.field === 'status'
	);
	if (!classicStatus) {
		return [...storedFilters];
	}

	return [
		classicStatus,
		...storedFilters.filter((filter) => filter.field !== 'status'),
	];
}

function projectRestoredView(baseView, durable) {
	return {
		...projectView(baseView, durable),
		showMedia: LAYOUTS[durable.mode].showMedia,
	};
}

function projectView(baseView, durable) {
	const layout = {
		...(isPlainObject(baseView.layout) ? baseView.layout : {}),
		...durable[durable.mode],
	};

	return {
		...baseView,
		type: durable.mode,
		perPage: durable.rowsPerPage,
		sort: durable.ordering
			? {
					field: durable.ordering.fieldId,
					direction: durable.ordering.direction,
				}
			: undefined,
		fields: [...durable.visibleFieldIds],
		showLevels: durable.showLevels,
		layout,
	};
}

function encodeDocument(durable, defaults, defaultFilters = []) {
	const overrides = {};

	if (durable.mode !== defaults.mode) {
		overrides.mode = durable.mode;
	}
	if (durable.rowsPerPage !== defaults.rowsPerPage) {
		overrides.rowsPerPage = durable.rowsPerPage;
	}
	if (!appearanceDocumentsEqual(durable.ordering, defaults.ordering)) {
		overrides.ordering = durable.ordering;
	}
	if (!arraysEqual(durable.visibleFieldIds, defaults.visibleFieldIds)) {
		overrides.visibleFieldIds = [...durable.visibleFieldIds];
	}
	if (durable.showLevels !== defaults.showLevels) {
		overrides.showLevels = durable.showLevels;
	}
	if (
		durable.filters !== undefined &&
		!filtersEqual(durable.filters, defaultFilters)
	) {
		overrides.filters = [...durable.filters];
	}

	Object.assign(overrides, encodeLayoutOverrides(durable, defaults));

	if (Object.keys(overrides).length === 0) {
		return null;
	}

	return { version: 1, overrides };
}

function encodeLayout(layout, defaults, includePreviewSize) {
	const encoded = {};
	if (layout.density !== defaults.density) {
		encoded.density = layout.density;
	}
	if (includePreviewSize && layout.previewSize !== defaults.previewSize) {
		encoded.previewSize = layout.previewSize;
	}
	return encoded;
}

function parseLayoutOverrides(source) {
	const overrides = {};
	for (const mode of MODES) {
		const layout = parseLayout(
			source[mode],
			LAYOUTS[mode].persistPreviewSize
		);
		if (Object.keys(layout).length > 0) {
			overrides[mode] = layout;
		}
	}
	return overrides;
}

function encodeLayoutOverrides(durable, defaults) {
	const overrides = {};
	for (const mode of MODES) {
		const encoded = encodeLayout(
			durable[mode] || {},
			defaults[mode] || {},
			LAYOUTS[mode].persistPreviewSize
		);
		if (Object.keys(encoded).length > 0) {
			overrides[mode] = encoded;
		}
	}
	return overrides;
}

function emptyLayouts() {
	const layouts = {};
	for (const mode of MODES) {
		layouts[mode] = {};
	}
	return layouts;
}

function copyLayouts(source) {
	const layouts = {};
	for (const mode of MODES) {
		layouts[mode] = { ...(source[mode] || {}) };
	}
	return layouts;
}

function parseOrdering(raw, capabilities) {
	if (
		!isPlainObject(raw) ||
		typeof raw.fieldId !== 'string' ||
		!capabilities.sortableIds.has(raw.fieldId) ||
		(raw.direction !== 'asc' && raw.direction !== 'desc')
	) {
		return null;
	}

	return {
		fieldId: raw.fieldId,
		direction: raw.direction,
	};
}

function parseLayout(raw, includePreviewSize) {
	if (!isPlainObject(raw)) {
		return {};
	}

	const layout = {};
	if (DENSITIES.has(raw.density)) {
		layout.density = raw.density;
	}
	if (
		includePreviewSize &&
		typeof raw.previewSize === 'number' &&
		Number.isFinite(raw.previewSize) &&
		raw.previewSize > 0
	) {
		layout.previewSize = raw.previewSize;
	}
	return layout;
}

function parseFilters(raw, capabilities) {
	const filters = [];
	for (const item of raw) {
		if (!isPlainObject(item)) {
			continue;
		}
		const field = typeof item.field === 'string' ? item.field : '';
		const operator = typeof item.operator === 'string' ? item.operator : '';
		if (
			field === '' ||
			!FILTER_OPERATORS.has(operator) ||
			!Object.prototype.hasOwnProperty.call(item, 'value') ||
			item.value === undefined ||
			!capabilities.knownIds.has(field)
		) {
			continue;
		}
		filters.push({
			field,
			operator,
			value: item.value,
		});
	}
	return filters;
}

function filterKnownIds(raw, capabilities) {
	const fieldIds = [];
	for (const fieldId of raw) {
		if (
			typeof fieldId === 'string' &&
			capabilities.knownIds.has(fieldId) &&
			!fieldIds.includes(fieldId)
		) {
			fieldIds.push(fieldId);
		}
	}
	return fieldIds;
}

function getAutoVisibleFieldIds(view, visibleFieldIds) {
	const durableIds = new Set(visibleFieldIds);
	return new Set(
		(view.fields || []).filter((fieldId) => !durableIds.has(fieldId))
	);
}

function clampRowsPerPage(value) {
	if (ROWS_PER_PAGE.includes(value)) {
		return value;
	}
	if (!Number.isFinite(value)) {
		return 20;
	}

	return ROWS_PER_PAGE.reduce((closest, candidate) =>
		Math.abs(candidate - value) < Math.abs(closest - value)
			? candidate
			: closest
	);
}

function arraysEqual(a, b) {
	return (
		Array.isArray(a) &&
		Array.isArray(b) &&
		a.length === b.length &&
		a.every((value, index) => value === b[index])
	);
}

function isPlainObject(value) {
	return value !== null && typeof value === 'object' && !Array.isArray(value);
}
