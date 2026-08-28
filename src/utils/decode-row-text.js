/**
 * WordPress Dependencies
 */
import { decodeEntities } from '@wordpress/html-entities';

/* global globalThis */

const TEXT_KEYS = ['title', 'parentPostTitle', 'author'];

function getTaxonomyTextKeys() {
	const boot = globalThis.window?.prcWpAdminDataview || {};
	const taxonomies = boot.taxonomies;
	if (!taxonomies || typeof taxonomies !== 'object') {
		return [];
	}
	return Object.keys(taxonomies);
}

function decodeLabeledArray(value) {
	if (!Array.isArray(value)) {
		return value;
	}
	return value.map((entry) => {
		if (
			!entry ||
			typeof entry !== 'object' ||
			typeof entry.label !== 'string'
		) {
			return entry;
		}
		return { ...entry, label: decodeEntities(entry.label) };
	});
}

/**
 * Decode HTML character references on list-row text fields.
 *
 * Domain REST paths can still emit `get_the_title()` output. DataViews
 * interpolates these strings as React text, which does not decode entities.
 *
 * @param {Object} item List row.
 * @return {Object} Row with Unicode text fields.
 */
export function decodeRowText(item) {
	if (!item || typeof item !== 'object') {
		return item;
	}

	const next = { ...item };
	const keys = new Set([...TEXT_KEYS, ...getTaxonomyTextKeys()]);
	for (const key of keys) {
		if (typeof next[key] === 'string') {
			next[key] = decodeEntities(next[key]);
		}
	}
	for (const [key, value] of Object.entries(next)) {
		if (Array.isArray(value)) {
			next[key] = decodeLabeledArray(value);
		}
	}
	return next;
}
