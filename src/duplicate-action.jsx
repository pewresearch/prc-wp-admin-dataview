/**
 * WordPress Dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	__experimentalText as Text,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { dispatch } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

export const DUPLICATE_LABEL = __('Duplicate', 'prc-wp-admin-dataview');

const duplicatingIds = new Set();
const DUPLICATE_NOTICE_ID = 'prc-wp-admin-dataview-duplicate';

async function duplicateItems({
	items,
	postType,
	onRefresh,
	onActionPerformed,
}) {
	const { createErrorNotice, createSuccessNotice } = dispatch(noticesStore);
	const eligible = items.filter(
		(item) => item?.id && !duplicatingIds.has(item.id)
	);
	if (!eligible.length) {
		return false;
	}

	eligible.forEach((item) => duplicatingIds.add(item.id));
	const count = eligible.length;

	try {
		const results = await Promise.allSettled(
			eligible.map((item) =>
				apiFetch({
					path: '/prc-api/v3/wp-admin-dataview/duplicate',
					method: 'POST',
					data: {
						postId: item.id,
						postType,
					},
				})
			)
		);
		const succeeded = results.filter(
			(result) => result.status === 'fulfilled'
		).length;

		if (succeeded === count) {
			createSuccessNotice(
				count === 1
					? __('Item duplicated.', 'prc-wp-admin-dataview')
					: __('Items duplicated.', 'prc-wp-admin-dataview'),
				{ type: 'snackbar', id: DUPLICATE_NOTICE_ID }
			);
			onActionPerformed?.(eligible);
			onRefresh?.();
			return true;
		}

		if (succeeded > 0) {
			createErrorNotice(
				sprintf(
					/* translators: 1: number of successful copies. 2: total number of items. */
					__(
						'%1$d of %2$d items duplicated.',
						'prc-wp-admin-dataview'
					),
					succeeded,
					count
				),
				{ type: 'snackbar', id: DUPLICATE_NOTICE_ID }
			);
			onActionPerformed?.(eligible);
			onRefresh?.();
			return true;
		}

		const firstError = results.find(
			(result) => result.status === 'rejected'
		)?.reason;
		createErrorNotice(
			firstError?.message ||
				(count === 1
					? __(
							'Could not duplicate this item.',
							'prc-wp-admin-dataview'
						)
					: __(
							'Could not duplicate the selected items.',
							'prc-wp-admin-dataview'
						)),
			{ type: 'snackbar', id: DUPLICATE_NOTICE_ID }
		);
		return false;
	} finally {
		eligible.forEach((item) => duplicatingIds.delete(item.id));
	}
}

export default function DuplicateModal({
	items,
	closeModal,
	onActionPerformed,
	postType,
	onRefresh,
}) {
	const [isDuplicating, setIsDuplicating] = useState(false);
	const count = items.length;
	const message =
		count === 1
			? __(
					'Are you sure you want to duplicate this item?',
					'prc-wp-admin-dataview'
				)
			: __(
					'Are you sure you want to duplicate these items?',
					'prc-wp-admin-dataview'
				);
	const draftNote =
		count === 1
			? __('This creates a draft copy.', 'prc-wp-admin-dataview')
			: __('This creates draft copies.', 'prc-wp-admin-dataview');

	const handleConfirm = async () => {
		setIsDuplicating(true);
		try {
			const didDuplicate = await duplicateItems({
				items,
				postType,
				onRefresh,
				onActionPerformed,
			});
			if (didDuplicate) {
				closeModal?.();
			}
		} finally {
			setIsDuplicating(false);
		}
	};

	return (
		<VStack spacing={3}>
			<Text>{message}</Text>
			<Text>{draftNote}</Text>
			<VStack spacing={2} direction="row" justify="flex-end">
				<Button
					variant="tertiary"
					onClick={closeModal}
					disabled={isDuplicating}
				>
					{__('Cancel', 'prc-wp-admin-dataview')}
				</Button>
				<Button
					variant="primary"
					onClick={handleConfirm}
					isBusy={isDuplicating}
					disabled={isDuplicating}
				>
					{DUPLICATE_LABEL}
				</Button>
			</VStack>
		</VStack>
	);
}
