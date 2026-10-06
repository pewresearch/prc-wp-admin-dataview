/**
 * Whether a click is a Ctrl/Cmd+Click on a real link inside the list, which
 * the browser should handle as "open in new tab".
 *
 * @param {MouseEvent}   event     Click event.
 * @param {Element|null} container List container element.
 * @return {boolean} Whether the click should bypass DataViews row selection.
 */
export function isNewTabLinkClick(event, container) {
	if (!container || event.button !== 0) {
		return false;
	}
	if (!event.metaKey && !event.ctrlKey) {
		return false;
	}
	const link = event.target?.closest?.('a[href]');
	return Boolean(link && container.contains(link));
}
