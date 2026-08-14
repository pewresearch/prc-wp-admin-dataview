/**
 * Stable JSON compare for DataViews filter arrays.
 *
 * @param {Array} a Filters.
 * @param {Array} b Filters.
 * @return {boolean} Whether equal.
 */
export function filtersEqual(a = [], b = []) {
	return (
		JSON.stringify(normalizeFilters(a)) ===
		JSON.stringify(normalizeFilters(b))
	);
}

/**
 * Sort filters by field+operator for stable comparison.
 *
 * @param {Array} filters Filters.
 * @return {Array} Normalized copy.
 */
export function normalizeFilters(filters = []) {
	return [...(filters || [])]
		.map((filter) => ({
			field: filter?.field ?? '',
			operator: filter?.operator ?? '',
			value: filter?.value,
		}))
		.sort((left, right) => {
			const leftKey = `${left.field}:${left.operator}`;
			const rightKey = `${right.field}:${right.operator}`;
			return leftKey.localeCompare(rightKey);
		});
}

/**
 * Whether the view currently has any filters worth saving.
 *
 * @param {Array} filters Filters.
 * @return {boolean} True when non-empty.
 */
export function hasFilters(filters = []) {
	return Array.isArray(filters) && filters.length > 0;
}
