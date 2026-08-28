export const STATUS_BADGE_TONES = {
	publish: 'publish',
	draft: 'draft',
	future: 'future',
	private: 'private',
	pending: 'pending',
	trash: 'trash',
};

export function getStatusBadgeTone(status) {
	return STATUS_BADGE_TONES[status] || 'draft';
}
