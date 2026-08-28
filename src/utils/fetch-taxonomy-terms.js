/**
 * WordPress Dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { decodeEntities } from '@wordpress/html-entities';
import { addQueryArgs } from '@wordpress/url';

const termPromises = new Map();

/**
 * Load DataViews filter elements for a registry taxonomy.
 *
 * Caches one promise per post type + taxonomy so opening the same chip
 * twice does not refetch. A failed request is dropped so the next open retries.
 *
 * @param {string} taxonomy Taxonomy slug.
 * @param {string} postType Post type.
 * @return {Promise<Array<{value: string, label: string}>>} Term options.
 */
export function fetchTaxonomyTerms(taxonomy, postType) {
	const key = `${postType}:${taxonomy}`;
	if (!termPromises.has(key)) {
		const path = addQueryArgs('/prc-api/v3/wp-admin-dataview/terms', {
			taxonomy,
			post_type: postType,
		});
		const request = apiFetch({ path })
			.then((data) =>
				(Array.isArray(data) ? data : []).map((term) => {
					if (!term || typeof term !== 'object') {
						return term;
					}
					return {
						...term,
						label:
							typeof term.label === 'string'
								? decodeEntities(term.label)
								: term.label,
					};
				})
			)
			.catch((error) => {
				termPromises.delete(key);
				throw error;
			});
		termPromises.set(key, request);
	}
	return termPromises.get(key);
}

/**
 * Drop cached term lists. Used by tests.
 */
export function resetTaxonomyTermsCache() {
	termPromises.clear();
}
