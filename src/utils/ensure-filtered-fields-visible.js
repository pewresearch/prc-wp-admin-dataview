/**
 * Filter-only fields that never become table columns.
 */
const FILTER_ONLY_FIELDS = new Set(['watchingOnly', 'activeEditors']);

/**
 * Map filter field ids onto the column they should reveal.
 *
 * @param {string} fieldId Filter field id.
 * @return {string|null} Column field id, or null when no column exists.
 */
function columnIdForFilter(fieldId) {
	if (!fieldId || FILTER_ONLY_FIELDS.has(fieldId)) {
		return null;
	}
	if (fieldId === 'parentFamily') {
		return 'parentPost';
	}
	return fieldId;
}

/**
 * Whether a filter currently constrains the list.
 *
 * @param {Object} filter DataViews filter.
 * @return {boolean} True when the filter has a usable value.
 */
export function hasActiveFilterValue(filter) {
	if (!filter || typeof filter !== 'object') {
		return false;
	}
	const { value } = filter;
	if (value === undefined || value === null || value === '') {
		return false;
	}
	if (Array.isArray(value) && value.length === 0) {
		return false;
	}
	return true;
}

/**
 * Ensure columns for active filters are present in view.fields.
 *
 * DataViews does not do this natively: filters and visible fields are
 * independent. Adds missing columns; never removes columns the user hid.
 *
 * @param {Object} view DataViews view.
 * @return {Object} View with filtered columns revealed when needed.
 */
export function ensureFilteredFieldsVisible(view) {
	if (!view || typeof view !== 'object' || !Array.isArray(view.filters)) {
		return view;
	}

	const visible = Array.isArray(view.fields) ? [...view.fields] : [];
	let changed = false;

	for (const filter of view.filters) {
		if (!hasActiveFilterValue(filter)) {
			continue;
		}
		const columnId = columnIdForFilter(filter.field);
		if (!columnId || columnId === view.titleField) {
			continue;
		}
		if (!visible.includes(columnId)) {
			visible.push(columnId);
			changed = true;
		}
	}

	if (!changed) {
		return view;
	}

	return {
		...view,
		fields: visible,
	};
}
