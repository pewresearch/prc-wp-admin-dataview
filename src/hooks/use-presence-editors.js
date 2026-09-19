/**
 * WordPress Dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal Dependencies
 */
import { mapRoomsToEditors } from '../utils/map-rooms-to-editors';

const POLL_INTERVAL_MS = 15000;
const EMPTY_MAP = new Map();

function isPresenceApiEnabled() {
	return (
		typeof window !== 'undefined' &&
		window.prcPlatform?.presenceApiEnabled === '1'
	);
}

/**
 * @param {string} postType
 * @return {Map<number, Array<{ userId: number, displayName: string, avatarUrl: string }>>} Editors keyed by post id.
 */
export default function usePresenceEditorsByPost(postType) {
	const [editorsByPostId, setEditorsByPostId] = useState(EMPTY_MAP);
	const intervalRef = useRef(null);

	const fetchRooms = useCallback(async () => {
		if (!isPresenceApiEnabled() || !postType) {
			setEditorsByPostId(EMPTY_MAP);
			return;
		}
		if (
			typeof document !== 'undefined' &&
			document.visibilityState === 'hidden'
		) {
			return;
		}

		let rooms;
		try {
			rooms = await apiFetch({
				path: '/wp-presence/v1/presence/rooms?per_page=100',
			});
		} catch {
			return;
		}
		setEditorsByPostId(mapRoomsToEditors(rooms, postType));
	}, [postType]);

	useEffect(() => {
		if (!isPresenceApiEnabled()) {
			setEditorsByPostId(EMPTY_MAP);
			return undefined;
		}

		fetchRooms();
		intervalRef.current = setInterval(fetchRooms, POLL_INTERVAL_MS);

		const onVisibility = () => {
			if (document.visibilityState === 'visible') {
				fetchRooms();
			}
		};
		document.addEventListener('visibilitychange', onVisibility);

		return () => {
			if (intervalRef.current) {
				clearInterval(intervalRef.current);
			}
			document.removeEventListener('visibilitychange', onVisibility);
		};
	}, [fetchRooms]);

	return editorsByPostId;
}
