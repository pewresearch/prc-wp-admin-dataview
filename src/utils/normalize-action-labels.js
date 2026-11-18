export const DEFAULT_PROPER_NOUNS = [
	'WordPress',
	'PRC',
	'PDF',
	'CSV',
	'ID',
	'URL',
	'AI',
	'Apple News',
];

/**
 * Convert a label to sentence case, keeping proper nouns and all-caps words.
 *
 * @param {string}        text        Label text.
 * @param {Array<string>} properNouns Words or phrases to keep as written.
 * @return {string} Sentence-case label.
 */
export function toSentenceCase(text, properNouns = DEFAULT_PROPER_NOUNS) {
	if (typeof text !== 'string' || !text) {
		return text;
	}

	const keep = new Map(properNouns.map((noun) => [noun.toLowerCase(), noun]));

	let result = text
		.split(/(\s+)/)
		.map((part) => {
			if (!part.trim()) {
				return part;
			}
			if (
				part.length > 1 &&
				part === part.toUpperCase() &&
				/[A-Z]/.test(part)
			) {
				return part;
			}
			return part.toLowerCase();
		})
		.join('');

	// Restore multi-word and single-word proper nouns, longest first.
	[...keep.entries()]
		.sort((a, b) => b[0].length - a[0].length)
		.forEach(([lower, original]) => {
			const escaped = lower.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
			result = result.replace(
				new RegExp(`(^|[^\\w])${escaped}(?![\\w])`, 'gi'),
				(match, prefix) => `${prefix}${original}`
			);
		});

	return result.charAt(0).toUpperCase() + result.slice(1);
}

function normalizeLabel(label, properNouns) {
	if (typeof label === 'string') {
		return toSentenceCase(label, properNouns);
	}
	if (typeof label === 'function') {
		return (...args) => {
			const rendered = label(...args);
			return typeof rendered === 'string'
				? toSentenceCase(rendered, properNouns)
				: rendered;
		};
	}
	return label;
}

/**
 * Normalize `label` and `modalHeader` on every DataViews action.
 *
 * @param {Array<Object>} actions     DataViews actions.
 * @param {Array<string>} properNouns Words to keep as written.
 * @return {Array<Object>} Actions with sentence-case labels.
 */
export function normalizeActionLabels(
	actions,
	properNouns = DEFAULT_PROPER_NOUNS
) {
	if (!Array.isArray(actions)) {
		return actions;
	}

	return actions.map((action) => {
		const next = { ...action };
		if ('label' in next) {
			next.label = normalizeLabel(next.label, properNouns);
		}
		if (typeof next.modalHeader === 'string') {
			next.modalHeader = toSentenceCase(next.modalHeader, properNouns);
		}
		return next;
	});
}
