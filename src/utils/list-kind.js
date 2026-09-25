export const COLLECTION_KIND = 'collection';

/**
 * Whether the current list is a non-post collection (no /wp/v2 routes behind rows).
 *
 * @param {Object} [boot] Localized shell data.
 * @return {boolean} True for collection lists.
 */
export function isCollectionList(boot = window?.prcWpAdminDataview) {
	return boot?.kind === COLLECTION_KIND;
}
