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
import { useDispatch } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { applyFilters } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { edit, external, trash } from '@wordpress/icons';
import { store as noticesStore } from '@wordpress/notices';

const EDIT_LABEL = __('Edit', 'prc-wp-admin-dataview');
const TRASH_LABEL = __('Move to Trash', 'prc-wp-admin-dataview');

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

function TrashActionLabel() {
	return (
		<ActionLabel
			text={TRASH_LABEL}
			icon={trash}
			className="prc-wp-admin-dataview__trash-action-label"
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

export default function getActions({ postType, config, onRefresh }) {
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
			isEligible: (item) => !!item?.id,
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
	];

	return applyFilters('prcWpAdminDataview.actions', baseActions, {
		postType,
		config,
		onRefresh,
	});
}
