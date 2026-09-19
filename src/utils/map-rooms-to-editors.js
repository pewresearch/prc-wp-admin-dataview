function roomPatternForPostType(postType) {
	return new RegExp(
		`^postType/${String(postType).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}:(\\d+)$`
	);
}

/**
 * @param {Array}  rooms
 * @param {string} postType
 * @return {Map<number, Array<{ userId: number, displayName: string, avatarUrl: string }>>} Editors keyed by post id.
 */
export function mapRoomsToEditors(rooms, postType) {
	const next = new Map();

	if (!Array.isArray(rooms)) {
		return next;
	}

	const pattern = roomPatternForPostType(postType);

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
