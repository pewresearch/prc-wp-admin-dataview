/**
 * WordPress Dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

const POLL_INTERVAL_MS = 15000;
const EMPTY_MAP = new Map();

function isPresenceApiEnabled() {
	return (
		typeof window !== 'undefined' &&
		window.prcPlatform?.presenceApiEnabled === '1'
	);
}

function roomPatternForPostType(postType) {
	return new RegExp(
		`^postType/${String(postType).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}:(\\d+)$`
	);
}

/**
 * Build postId → editors map from Presence /rooms payload for one post type.
 * Includes the current user so posts open in other tabs are visible on the list.
 *
 * @param {Array}  rooms
 * @param {string} postType
 * @return {Map<number, Array<{ userId: number, displayName: string, avatarUrl: string }>>}
 */
export function mapRoomsToEditors(rooms, postType) {
	const pattern = roomPatternForPostType(postType);
	const next = new Map();

	if (!Array.isArray(rooms)) {
		return next;
	}

	rooms.forEach((roomEntry) => {
		const match = pattern.exec(roomEntry?.room || '');
		if (!match) {
			return;
		}
		const postId = Number(match[1]);
		if (!postId) {
			return;
		}

		const byUser = new Map();
		(roomEntry.users || []).forEach((user) => {
			const userId = Number(user.user_id);
			if (!userId) {
				return;
			}
			if (byUser.has(userId)) {
				return;
			}
			byUser.set(userId, {
				userId,
				displayName: user.display_name || `#${userId}`,
				avatarUrl: user.avatar_url || '',
			});
		});

		if (byUser.size) {
			next.set(postId, Array.from(byUser.values()));
		}
	});

	return next;
}

/**
 * Poll Presence API rooms and return editors keyed by post id (includes self).
 *
 * @param {string} postType
 * @return {Map<number, Array<{ userId: number, displayName: string, avatarUrl: string }>>}
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

		try {
			const rooms = await apiFetch({
				path: '/wp-presence/v1/presence/rooms?per_page=100',
			});
			setEditorsByPostId(mapRoomsToEditors(rooms, postType));
		} catch (error) {
			// Presence is optional; keep last good map on transient failures.
		}
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
