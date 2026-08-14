/**
 * Sync DataViews filters to/from flat URL query args.
 *
 * Filter values use dvf_<fieldId> (comma-joined, per-segment encoded).
 * Non-default operators use dvop_<fieldId>.
 * Legacy status uses status= and post_status=.
 */

import { hasActiveFilterValue } from './ensure-filtered-fields-visible.js';

export const RESERVED_URL_KEYS = new Set([
	'page',
	'post_type',
	'classic',
	'paged',
	'action',
	'_wpnonce',
	'_wp_http_referer',
	'p',
]);

const DVF_PREFIX = 'dvf_';
const DVOP_PREFIX = 'dvop_';
const STATUS_FIELD = 'status';
const POST_STATUS_KEY = 'post_status';
const PARENT_FAMILY_FIELD = 'parentFamily';
const DEFAULT_OPERATOR = 'isAny';
const PARENT_FAMILY_OPERATOR = 'is';

/**
 * Normalize search input to a URLSearchParams instance.
 *
 * @param {string|URLSearchParams|undefined|null} search Search string or params.
 * @return {URLSearchParams} Params instance.
 */
export function getSearchParams(search) {
	if (search instanceof URLSearchParams) {
		return search;
	}
	const raw =
		typeof search === 'string'
			? search.startsWith('?')
				? search.slice(1)
				: search
			: '';
	return new URLSearchParams(raw);
}

/**
 * @param {string} value Possibly encoded URI component.
 * @return {string} Decoded value, or the original on failure.
 */
function safeDecode(value) {
	try {
		return decodeURIComponent(String(value).replace(/\+/g, ' '));
	} catch {
		return String(value);
	}
}

/**
 * Expand search input into [key, rawValue] pairs.
 * For string input, rawValue keeps comma separators and per-segment encoding.
 *
 * @param {string|URLSearchParams|undefined|null} search Search input.
 * @return {Array<[string, string]>} Entries.
 */
function getRawEntries(search) {
	if (search instanceof URLSearchParams) {
		return [...search.entries()].map(([key, value]) => [
			key,
			String(value)
				.split(',')
				.map((segment) => encodeURIComponent(segment))
				.join(','),
		]);
	}

	const raw =
		typeof search === 'string'
			? search.startsWith('?')
				? search.slice(1)
				: search
			: '';
	if (!raw) {
		return [];
	}

	return raw
		.split('&')
		.filter(Boolean)
		.map((part) => {
			const eq = part.indexOf('=');
			if (eq === -1) {
				return [safeDecode(part), ''];
			}
			return [safeDecode(part.slice(0, eq)), part.slice(eq + 1)];
		});
}

/**
 * Decode a comma-joined filter value.
 *
 * @param {string} rawValue Raw query value.
 * @return {string[]} Decoded segments.
 */
function decodeCommaJoined(rawValue) {
	if (rawValue === undefined || rawValue === null || rawValue === '') {
		return [];
	}
	return rawValue
		.split(',')
		.map((segment) => safeDecode(segment))
		.filter((segment) => segment !== '');
}

/**
 * Encode filter values as comma-joined URI components.
 *
 * @param {string|number|Array<string|number>} value Filter value.
 * @return {string} Encoded query value.
 */
function encodeCommaJoined(value) {
	const values = Array.isArray(value) ? value : [value];
	return values
		.map((segment) => encodeURIComponent(String(segment)))
		.join(',');
}

/**
 * Default operator when dvop_* is absent.
 *
 * @param {string} fieldId View field id.
 * @return {string} Operator.
 */
function defaultOperatorForField(fieldId) {
	return fieldId === PARENT_FAMILY_FIELD
		? PARENT_FAMILY_OPERATOR
		: DEFAULT_OPERATOR;
}

/**
 * Whether a query key is an owned filter value or operator key.
 *
 * @param {string} key Query key.
 * @return {boolean} True when the key belongs to filter sync.
 */
function isFilterOwnedKey(key) {
	return (
		key === STATUS_FIELD ||
		key === POST_STATUS_KEY ||
		key.startsWith(DVF_PREFIX) ||
		key.startsWith(DVOP_PREFIX)
	);
}

/**
 * Whether the search string carries explicit DataViews filter params.
 * post_status alone does not count (classic list redirects).
 *
 * @param {string|URLSearchParams|undefined|null} search Search input.
 * @return {boolean} True when explicit filter params are present.
 */
export function urlHasFilterParams(search) {
	const entries = getRawEntries(search);
	for (const [key] of entries) {
		if (key.startsWith(DVOP_PREFIX) || key.startsWith(DVF_PREFIX)) {
			return true;
		}
		if (key === STATUS_FIELD) {
			return true;
		}
	}
	return false;
}

/**
 * Parse DataViews filters from a search string or URLSearchParams.
 *
 * @param {string|URLSearchParams|undefined|null} search Search input.
 * @return {Array<{field: string, operator: string, value: *}>} Filters.
 */
export function parseFiltersFromSearch(search) {
	const entries = getRawEntries(search);
	const operators = new Map();
	const valuesByField = new Map();

	for (const [key, rawValue] of entries) {
		if (key.startsWith(DVOP_PREFIX)) {
			const fieldId = key.slice(DVOP_PREFIX.length);
			if (fieldId) {
				operators.set(fieldId, safeDecode(rawValue));
			}
			continue;
		}
		if (key.startsWith(DVF_PREFIX)) {
			const fieldId = key.slice(DVF_PREFIX.length);
			if (fieldId) {
				valuesByField.set(fieldId, rawValue);
			}
			continue;
		}
		if (key === STATUS_FIELD || key === POST_STATUS_KEY) {
			valuesByField.set(key, rawValue);
		}
	}

	const filters = [];
	const statusRaw = valuesByField.get(STATUS_FIELD);
	const postStatusRaw = valuesByField.get(POST_STATUS_KEY);

	if (statusRaw !== undefined) {
		const value = decodeCommaJoined(statusRaw);
		if (value.length > 0) {
			filters.push({
				field: STATUS_FIELD,
				operator:
					operators.get(STATUS_FIELD) ||
					defaultOperatorForField(STATUS_FIELD),
				value,
			});
		}
	} else if (postStatusRaw !== undefined) {
		const value = decodeCommaJoined(postStatusRaw);
		if (value.length > 0) {
			filters.push({
				field: STATUS_FIELD,
				operator: DEFAULT_OPERATOR,
				value,
			});
		}
	}

	for (const [fieldId, rawValue] of valuesByField) {
		if (fieldId === STATUS_FIELD || fieldId === POST_STATUS_KEY) {
			continue;
		}

		const operator =
			operators.get(fieldId) || defaultOperatorForField(fieldId);

		if (fieldId === PARENT_FAMILY_FIELD) {
			const segments = decodeCommaJoined(rawValue);
			const parsed = Number.parseInt(segments[0] ?? '', 10);
			if (!Number.isInteger(parsed)) {
				continue;
			}
			filters.push({
				field: fieldId,
				operator,
				value: parsed,
			});
			continue;
		}

		const value = decodeCommaJoined(rawValue);
		if (value.length === 0) {
			continue;
		}
		filters.push({
			field: fieldId,
			operator,
			value,
		});
	}

	return filters;
}

/**
 * Write active filters into the browser URL via history.replaceState.
 * Preserves path, hash, reserved WP keys, and unrelated query args.
 *
 * @param {Array<{field: string, operator: string, value: *}>|undefined} filters
 *        Active DataViews filters.
 * @param {{location?: Location, history?: History}|undefined} options
 *        Optional window substitutes for tests.
 */
export function syncFiltersToUrl(filters, options = {}) {
	const location = options.location || window.location;
	const historyApi = options.history || window.history;
	const currentEntries = getRawEntries(location.search);

	const preserved = [];
	for (const [key, rawValue] of currentEntries) {
		if (isFilterOwnedKey(key)) {
			continue;
		}
		preserved.push(
			`${encodeURIComponent(key)}=${encodeURIComponent(safeDecode(rawValue))}`
		);
	}

	const filterParts = [];
	const list = Array.isArray(filters) ? filters : [];

	for (const filter of list) {
		if (!hasActiveFilterValue(filter)) {
			continue;
		}
		const fieldId = filter.field;
		if (!fieldId || RESERVED_URL_KEYS.has(fieldId)) {
			continue;
		}

		const operator = filter.operator || defaultOperatorForField(fieldId);
		const defaultOperator = defaultOperatorForField(fieldId);

		if (fieldId === STATUS_FIELD) {
			filterParts.push(
				`${encodeURIComponent(STATUS_FIELD)}=${encodeCommaJoined(filter.value)}`
			);
			if (
				operator === DEFAULT_OPERATOR &&
				Array.isArray(filter.value) &&
				filter.value.length === 1
			) {
				filterParts.push(
					`${encodeURIComponent(POST_STATUS_KEY)}=${encodeURIComponent(String(filter.value[0]))}`
				);
			}
		} else if (fieldId === PARENT_FAMILY_FIELD) {
			filterParts.push(
				`${encodeURIComponent(DVF_PREFIX + fieldId)}=${encodeURIComponent(String(filter.value))}`
			);
		} else {
			filterParts.push(
				`${encodeURIComponent(DVF_PREFIX + fieldId)}=${encodeCommaJoined(filter.value)}`
			);
		}

		if (operator !== defaultOperator) {
			filterParts.push(
				`${encodeURIComponent(DVOP_PREFIX + fieldId)}=${encodeURIComponent(operator)}`
			);
		}
	}

	const search = [...preserved, ...filterParts].join('&');
	const nextUrl =
		location.pathname +
		(search ? `?${search}` : '') +
		(location.hash || '');

	historyApi.replaceState(historyApi.state ?? null, '', nextUrl);
}
