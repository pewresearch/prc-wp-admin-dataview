/**
 * WordPress Dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { addQueryArgs } from '@wordpress/url';

const REST_BASE = '/prc-api/v3/wp-admin-dataview/saved-filters';

/**
 * CRUD hook for per-post-type saved filter sets.
 *
 * @param {string} postType Post type.
 * @param {Array}  initial  Bootstrapped sets from localize.
 */
export default function useSavedFilters(postType, initial = []) {
	const [sets, setSets] = useState(() =>
		Array.isArray(initial) ? initial : []
	);
	const [isSaving, setIsSaving] = useState(false);
	const [error, setError] = useState(null);

	useEffect(() => {
		setSets(Array.isArray(initial) ? initial : []);
	}, [postType]); // eslint-disable-line react-hooks/exhaustive-deps -- reseed on post type change only

	const createSet = useCallback(
		async ({ name, filters }) => {
			setIsSaving(true);
			setError(null);
			try {
				const created = await apiFetch({
					path: REST_BASE,
					method: 'POST',
					data: { post_type: postType, name, filters },
				});
				setSets((current) => [...current, created]);
				return created;
			} catch (err) {
				setError(err);
				throw err;
			} finally {
				setIsSaving(false);
			}
		},
		[postType]
	);

	const updateSet = useCallback(
		async (id, payload) => {
			setIsSaving(true);
			setError(null);
			try {
				const path = addQueryArgs(`${REST_BASE}/${id}`, {
					post_type: postType,
				});
				const updated = await apiFetch({
					path,
					method: 'PUT',
					data: { post_type: postType, ...payload },
				});
				setSets((current) =>
					current.map((set) => (set.id === id ? updated : set))
				);
				return updated;
			} catch (err) {
				setError(err);
				throw err;
			} finally {
				setIsSaving(false);
			}
		},
		[postType]
	);

	const deleteSet = useCallback(
		async (id) => {
			setIsSaving(true);
			setError(null);
			try {
				const path = addQueryArgs(`${REST_BASE}/${id}`, {
					post_type: postType,
				});
				await apiFetch({
					path,
					method: 'DELETE',
				});
				setSets((current) => current.filter((set) => set.id !== id));
			} catch (err) {
				setError(err);
				throw err;
			} finally {
				setIsSaving(false);
			}
		},
		[postType]
	);

	return {
		sets,
		isSaving,
		error,
		createSet,
		updateSet,
		deleteSet,
	};
}
