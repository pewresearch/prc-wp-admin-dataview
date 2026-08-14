/**
 * WordPress Dependencies
 */
import { createSlotFill } from '@wordpress/components';

export const { Fill: HeaderActionsFill, Slot: HeaderActionsSlot } =
	createSlotFill('prcWpAdminDataview.HeaderActions');

export const { Fill: PageExtrasFill, Slot: PageExtrasSlot } = createSlotFill(
	'prcWpAdminDataview.PageExtras'
);

const PAGE_EXTRA_EVENT = 'prcWpAdminDataview.pageExtra';

export function emitPageExtra(type, payload) {
	window.dispatchEvent(
		new CustomEvent(PAGE_EXTRA_EVENT, {
			detail: { type, payload },
		})
	);
}

export function subscribePageExtra(listener) {
	const handleEvent = (event) => listener(event.detail);
	window.addEventListener(PAGE_EXTRA_EVENT, handleEvent);

	return () => window.removeEventListener(PAGE_EXTRA_EVENT, handleEvent);
}
