/**
 * WordPress Dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useState } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

export async function updateFieldRequest(postType, postId, field, value) {
	return apiFetch({
		path: '/prc-api/v3/wp-admin-dataview/field',
		method: 'POST',
		data: { postId, field, value, postType },
	});
}

export default function useUpdateField(
	postType,
	{ onSuccess, quiet = false } = {}
) {
	const [isSaving, setIsSaving] = useState(false);
	const { createNotice } = useDispatch(noticesStore);

	const updateField = useCallback(
		async (postId, field, value) => {
			setIsSaving(true);
			try {
				const result = await updateFieldRequest(
					postType,
					postId,
					field,
					value
				);
				onSuccess?.(result);
				if (!quiet) {
					createNotice(
						'success',
						__('Saved.', 'prc-wp-admin-dataview'),
						{ type: 'snackbar' }
					);
				}
				return result;
			} catch (error) {
				if (!quiet) {
					createNotice(
						'error',
						error?.message ||
							__(
								'Could not save field.',
								'prc-wp-admin-dataview'
							),
						{ type: 'snackbar' }
					);
				}
				throw error;
			} finally {
				setIsSaving(false);
			}
		},
		[postType, onSuccess, quiet, createNotice]
	);

	return { updateField, isSaving };
}
