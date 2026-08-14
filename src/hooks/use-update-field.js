/**
 * WordPress Dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useState } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

export default function useUpdateField(postType, onSuccess) {
	const [isSaving, setIsSaving] = useState(false);
	const { createNotice } = useDispatch(noticesStore);

	const updateField = useCallback(
		async (postId, field, value) => {
			setIsSaving(true);
			try {
				const result = await apiFetch({
					path: '/prc-api/v3/wp-admin-dataview/field',
					method: 'POST',
					data: { postId, field, value, postType },
				});
				onSuccess?.(result);
				createNotice('success', __('Saved.', 'prc-wp-admin-dataview'), {
					type: 'snackbar',
				});
				return result;
			} catch (error) {
				createNotice(
					'error',
					error?.message ||
						__('Could not save field.', 'prc-wp-admin-dataview'),
					{ type: 'snackbar' }
				);
				throw error;
			} finally {
				setIsSaving(false);
			}
		},
		[postType, onSuccess, createNotice]
	);

	return { updateField, isSaving };
}
