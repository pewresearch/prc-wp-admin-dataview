/**
 * WordPress Dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Icon,
	__experimentalText as Text,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { dispatch, useDispatch } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { applyFilters } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { copy, edit, external, trash, undo } from '@wordpress/icons';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal Dependencies
 */
import DuplicateModal, { DUPLICATE_LABEL } from './duplicate-action';

const EDIT_LABEL = __('Edit', 'prc-wp-admin-dataview');
const TRASH_LABEL = __('Move to Trash', 'prc-wp-admin-dataview');
const RESTORE_LABEL = __('Restore', 'prc-wp-admin-dataview');
const DELETE_PERMANENTLY_LABEL = __(
	'Delete Permanently',
	'prc-wp-admin-dataview'
);

/**
 * Dual label: text for the more-menu, icon for primary/bulk toolbars.
 * DataViews 17.2 still renders `label` as Button children and skips `icon`.
 *
 * @param {Object} props
 * @param {string} props.text
 * @param {Object} props.icon
 * @param {string} props.className
 * @return {Object} The rendered action label.
 */
function ActionLabel({ text, icon, className }) {
	return (
		<span className={className}>
			<span className={`${className}-text`}>{text}</span>
			<span className={`${className}-icon`}>
				<Icon icon={icon} />
			</span>
		</span>
	);
}

function EditActionLabel({ item }) {
	const className = 'prc-wp-admin-dataview__edit-action-label';
	const content = (
		<>
			<span className={`${className}-text`}>{EDIT_LABEL}</span>
			<span className={`${className}-icon`}>
				<Icon icon={edit} />
			</span>
		</>
	);

	if (!item?.edit_url) {
		return <span className={className}>{content}</span>;
	}

	return (
		<a
			className={className}
			href={item.edit_url}
			onClick={(event) => {
				event.stopPropagation();
			}}
		>
			{content}
		</a>
	);
}

function DuplicateActionLabel() {
	return (
		<ActionLabel
			text={DUPLICATE_LABEL}
			icon={copy}
			className="prc-wp-admin-dataview__duplicate-action-label"
		/>
	);
}

function TrashActionLabel() {
	return (
		<ActionLabel
			text={TRASH_LABEL}
			icon={trash}
			className="prc-wp-admin-dataview__trash-action-label"
		/>
	);
}

function RestoreActionLabel() {
	return (
		<ActionLabel
			text={RESTORE_LABEL}
			icon={undo}
			className="prc-wp-admin-dataview__restore-action-label"
		/>
	);
}

function DeletePermanentlyActionLabel() {
	return (
		<ActionLabel
			text={DELETE_PERMANENTLY_LABEL}
			icon={trash}
			className="prc-wp-admin-dataview__delete-permanently-action-label"
		/>
	);
}

const REST_BASES = {
	post: 'posts',
	page: 'pages',
};

function getItemRestPath(postType, id, restBase) {
	const base = restBase || REST_BASES[postType] || postType;
	return `/wp/v2/${base}/${id}`;
}

function TrashModal({
	items,
	closeModal,
	onActionPerformed,
	postType,
	restBase,
	onRefresh,
}) {
	const { createErrorNotice, createSuccessNotice } =
		useDispatch(noticesStore);
	const [isTrashing, setIsTrashing] = useState(false);
	const count = items.length;
	const message =
		count === 1
			? __(
					'Are you sure you want to move this item to the trash?',
					'prc-wp-admin-dataview'
				)
			: __(
					'Are you sure you want to move these items to the trash?',
					'prc-wp-admin-dataview'
				);

	const handleConfirm = async () => {
		setIsTrashing(true);

		try {
			await Promise.all(
				items.map((item) =>
					apiFetch({
						path: getItemRestPath(postType, item.id, restBase),
						method: 'DELETE',
						data: { force: false },
					})
				)
			);
			createSuccessNotice(
				count === 1
					? __('Item moved to trash.', 'prc-wp-admin-dataview')
					: __('Items moved to trash.', 'prc-wp-admin-dataview'),
				{ type: 'snackbar' }
			);
			onActionPerformed?.(items);
			onRefresh?.();
			closeModal?.();
		} catch (error) {
			createErrorNotice(
				error?.message ||
					__(
						'Could not move the selected item(s) to the trash.',
						'prc-wp-admin-dataview'
					),
				{ type: 'snackbar' }
			);
		} finally {
			setIsTrashing(false);
		}
	};

	return (
		<VStack spacing={3}>
			<Text>{message}</Text>
			<VStack spacing={2} direction="row" justify="flex-end">
				<Button
					variant="tertiary"
					onClick={closeModal}
					disabled={isTrashing}
				>
					{__('Cancel', 'prc-wp-admin-dataview')}
				</Button>
				<Button
					variant="primary"
					isDestructive
					onClick={handleConfirm}
					isBusy={isTrashing}
					disabled={isTrashing}
				>
					{__('Move to Trash', 'prc-wp-admin-dataview')}
				</Button>
			</VStack>
		</VStack>
	);
}

function DeletePermanentlyModal({
	items,
	closeModal,
	onActionPerformed,
	postType,
	restBase,
	onRefresh,
}) {
	const { createErrorNotice, createSuccessNotice } =
		useDispatch(noticesStore);
	const [isDeleting, setIsDeleting] = useState(false);
	const count = items.length;
	const message =
		count === 1
			? __(
					'Are you sure you want to permanently delete this item? This cannot be undone.',
					'prc-wp-admin-dataview'
				)
			: __(
					'Are you sure you want to permanently delete these items? This cannot be undone.',
					'prc-wp-admin-dataview'
				);

	const handleConfirm = async () => {
		setIsDeleting(true);

		try {
			await Promise.all(
				items.map((item) =>
					apiFetch({
						path: getItemRestPath(postType, item.id, restBase),
						method: 'DELETE',
						data: { force: true },
					})
				)
			);
			createSuccessNotice(
				count === 1
					? __('Item permanently deleted.', 'prc-wp-admin-dataview')
					: __('Items permanently deleted.', 'prc-wp-admin-dataview'),
				{ type: 'snackbar' }
			);
			onActionPerformed?.(items);
			onRefresh?.();
			closeModal?.();
		} catch (error) {
			createErrorNotice(
				error?.message ||
					__(
						'Could not permanently delete the selected item(s).',
						'prc-wp-admin-dataview'
					),
				{ type: 'snackbar' }
			);
		} finally {
			setIsDeleting(false);
		}
	};

	return (
		<VStack spacing={3}>
			<Text>{message}</Text>
			<VStack spacing={2} direction="row" justify="flex-end">
				<Button
					variant="tertiary"
					onClick={closeModal}
					disabled={isDeleting}
				>
					{__('Cancel', 'prc-wp-admin-dataview')}
				</Button>
				<Button
					variant="primary"
					isDestructive
					onClick={handleConfirm}
					isBusy={isDeleting}
					disabled={isDeleting}
				>
					{DELETE_PERMANENTLY_LABEL}
				</Button>
			</VStack>
		</VStack>
	);
}

async function restoreItems({
	items,
	postType,
	restBase,
	onRefresh,
	onActionPerformed,
}) {
	const { createErrorNotice, createSuccessNotice } = dispatch(noticesStore);
	const count = items.length;

	try {
		await Promise.all(
			items.map((item) =>
				apiFetch({
					path: getItemRestPath(postType, item.id, restBase),
					method: 'POST',
					data: { status: item.previousStatus || 'draft' },
				})
			)
		);
		createSuccessNotice(
			count === 1
				? __('Item restored.', 'prc-wp-admin-dataview')
				: __('Items restored.', 'prc-wp-admin-dataview'),
			{ type: 'snackbar' }
		);
		onActionPerformed?.(items);
		onRefresh?.();
	} catch (error) {
		createErrorNotice(
			error?.message ||
				__(
					'Could not restore the selected item(s).',
					'prc-wp-admin-dataview'
				),
			{ type: 'snackbar' }
		);
	}
}

export default function getActions({
	postType,
	config,
	onRefresh,
	onBulkEdit,
	hasEditableFields,
}) {
	const restBase = config?.restBase;

	const baseActions = [
		{
			id: 'edit',
			label: ([item]) => <EditActionLabel item={item} />,
			icon: edit,
			isPrimary: true,
			callback: ([item]) => {
				if (item?.edit_url) {
					window.location.href = item.edit_url;
				}
			},
			isEligible: (item) => !!item?.edit_url,
		},
		{
			id: 'duplicate',
			label: () => <DuplicateActionLabel />,
			icon: copy,
			isPrimary: true,
			supportsBulk: true,
			modalHeader: DUPLICATE_LABEL,
			isEligible: (item) =>
				!!item?.id &&
				item?.status !== 'trash' &&
				config?.duplicate?.enabled !== false,
			RenderModal: (props) => (
				<DuplicateModal
					{...props}
					postType={postType}
					onRefresh={onRefresh}
				/>
			),
		},
		{
			id: 'view',
			label: __('View', 'prc-wp-admin-dataview'),
			icon: external,
			callback: async ([item]) => {
				if (!item?.id) {
					return;
				}
				try {
					const record = await apiFetch({
						path: `${getItemRestPath(postType, item.id, restBase)}?context=edit`,
					});
					if (record?.link) {
						window.open(record.link, '_blank', 'noopener');
					}
				} catch (error) {
					// View is best-effort; edit remains available.
				}
			},
			isEligible: (item) => !!item?.id && item?.status !== 'trash',
		},
		{
			id: 'bulkEdit',
			label: __('Bulk edit', 'prc-wp-admin-dataview'),
			icon: edit,
			supportsBulk: true,
			callback: (items) => {
				onBulkEdit?.(items);
			},
			isEligible: (item) =>
				!!item?.id && item?.status !== 'trash' && hasEditableFields,
		},
		{
			id: 'trash',
			label: () => <TrashActionLabel />,
			icon: trash,
			modalHeader: TRASH_LABEL,
			isDestructive: true,
			supportsBulk: true,
			isEligible: (item) => !!item?.id && item?.status !== 'trash',
			RenderModal: (props) => (
				<TrashModal
					{...props}
					postType={postType}
					restBase={restBase}
					onRefresh={onRefresh}
				/>
			),
		},
		{
			id: 'restore',
			label: () => <RestoreActionLabel />,
			icon: undo,
			isPrimary: true,
			supportsBulk: true,
			isEligible: (item) => !!item?.id && item?.status === 'trash',
			callback: (items, { onActionPerformed } = {}) =>
				restoreItems({
					items,
					postType,
					restBase,
					onRefresh,
					onActionPerformed,
				}),
		},
		{
			id: 'deletePermanently',
			label: () => <DeletePermanentlyActionLabel />,
			icon: trash,
			modalHeader: DELETE_PERMANENTLY_LABEL,
			isDestructive: true,
			supportsBulk: true,
			isEligible: (item) => !!item?.id && item?.status === 'trash',
			RenderModal: (props) => (
				<DeletePermanentlyModal
					{...props}
					postType={postType}
					restBase={restBase}
					onRefresh={onRefresh}
				/>
			),
		},
	];

	return applyFilters('prcWpAdminDataview.actions', baseActions, {
		postType,
		config,
		onRefresh,
	});
}
