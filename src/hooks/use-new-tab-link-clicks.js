/**
 * WordPress Dependencies
 */
import { useEffect } from '@wordpress/element';

/**
 * Internal Dependencies
 */
import { isNewTabLinkClick } from '../utils/new-tab-link-click';

/**
 * Let Ctrl/Cmd+Click on list links open a new tab instead of toggling row
 * selection.
 *
 * DataViews rows toggle selection on Ctrl/Cmd+Click from a React
 * `onClickCapture` and call `preventDefault()`, which also swallows clicks on
 * item links. React dispatches capture handlers from its root container, so
 * the click is stopped on `document` (an ancestor of that root) before React
 * sees it. The browser default (open in new tab) still runs.
 *
 * @param {{ current: Element|null }} containerRef List container ref.
 */
export default function useNewTabLinkClicks(containerRef) {
	useEffect(() => {
		const onClickCapture = (event) => {
			if (isNewTabLinkClick(event, containerRef.current)) {
				event.stopPropagation();
			}
		};
		document.addEventListener('click', onClickCapture, true);
		return () =>
			document.removeEventListener('click', onClickCapture, true);
	}, [containerRef]);
}
