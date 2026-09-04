const STORAGE_PREFIX = 'prcWpAdminDataview.appearance.';
const EMPTY_DOCUMENT = { version: 1, overrides: {} };

/**
 * Build the per-post-type appearance key.
 *
 * @param {string} postType Post type.
 * @return {string} localStorage key.
 */
export function appearanceStorageKey(postType) {
	return `${STORAGE_PREFIX}${postType}`;
}

/**
 * Read the stored appearance document.
 *
 * @param {string} postType Post type.
 * @return {Object|null} Parsed document, or null when missing or invalid.
 */
export function readAppearanceDocument(postType) {
	const storage = getLocalStorage();
	if (!storage) {
		return null;
	}

	try {
		const raw = storage.getItem(appearanceStorageKey(postType));
		if (raw === null) {
			return null;
		}

		const parsed = JSON.parse(raw);
		if (
			!isPlainObject(parsed) ||
			parsed.version !== 1 ||
			!isPlainObject(parsed.overrides)
		) {
			return null;
		}

		return parsed;
	} catch {
		return null;
	}
}

/**
 * Write the appearance document. `null` persists an empty document.
 *
 * @param {string}      postType Post type.
 * @param {Object|null} document Document to store, or null for a reset.
 */
export function writeAppearanceDocument(postType, document) {
	const storage = getLocalStorage();
	if (!storage) {
		return;
	}

	const key = appearanceStorageKey(postType);
	try {
		storage.setItem(
			key,
			JSON.stringify(document === null ? EMPTY_DOCUMENT : document)
		);
	} catch {
		// Quota and private-mode storage throw; keep the in-memory session.
	}
}

/**
 * Drop table mode from a document.
 *
 * @param {Object|null} document Appearance document.
 * @return {Object|null} Document without table mode, or null when nothing remains.
 */
export function stripTableMode(document) {
	if (!isPlainObject(document) || !isPlainObject(document.overrides)) {
		return document;
	}

	if (document.overrides.mode !== 'table') {
		return document;
	}

	const overrides = { ...document.overrides };
	delete overrides.mode;
	if (Object.keys(overrides).length === 0) {
		return null;
	}

	return {
		version: document.version,
		overrides,
	};
}

/**
 * Whether the admin viewport is narrower than WordPress `medium`.
 *
 * @return {boolean} True on a narrow viewport.
 */
export function isNarrowAdminViewport() {
	if (
		typeof window === 'undefined' ||
		typeof window.matchMedia !== 'function'
	) {
		return false;
	}

	return window.matchMedia('(max-width: 782px)').matches;
}

/**
 * Default DataViews layout for this viewport.
 *
 * @return {'list'|'table'} Layout type.
 */
export function getDefaultLayoutType() {
	return isNarrowAdminViewport() ? 'list' : 'table';
}

/**
 * Choose localStorage first, then seed from boot user-meta.
 *
 * @param {Object}  options              Resolve options.
 * @param {string}  options.postType     Post type.
 * @param {*}       options.bootDocument Localized appearance document.
 * @param {boolean} options.isNarrow     Whether the viewport is narrow.
 * @return {Object|null} Document to apply (may be null).
 */
export function resolveAppearanceDocument({
	postType,
	bootDocument,
	isNarrow,
}) {
	const stored = readAppearanceDocument(postType);
	if (stored !== null) {
		return stored;
	}

	if (!isNarrow) {
		return bootDocument ?? null;
	}

	return stripTableMode(bootDocument) ?? null;
}

function getLocalStorage() {
	try {
		if (typeof window === 'undefined' || !window.localStorage) {
			return null;
		}

		return window.localStorage;
	} catch {
		return null;
	}
}

function isPlainObject(value) {
	return value !== null && typeof value === 'object' && !Array.isArray(value);
}
