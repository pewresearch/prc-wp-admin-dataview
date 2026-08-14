/**
 * WordPress Dependencies
 */
import { Button, TextControl } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { closeSmall } from '@wordpress/icons';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal Dependencies
 */
import DeleteSavedFilterModal from './delete-modal';
import { filtersEqual, hasFilters } from './helpers';
import SavedFilterSetList from './set-list';

export default function SavedFiltersShelf({
	view,
	onChangeView,
	activeSavedFilterId,
	onActiveSavedFilterIdChange,
	onClose,
	sets,
	isSaving,
	createSet,
	updateSet,
	deleteSet,
}) {
	const { createNotice } = useDispatch(noticesStore);
	const [newName, setNewName] = useState('');
	const [renamingId, setRenamingId] = useState(null);
	const [renameValue, setRenameValue] = useState('');
	const [pendingDeleteId, setPendingDeleteId] = useState(null);

	const currentFilters = useMemo(() => view?.filters || [], [view?.filters]);
	const canSave = hasFilters(currentFilters);

	const activeSet = useMemo(
		() => sets.find((set) => set.id === activeSavedFilterId) || null,
		[sets, activeSavedFilterId]
	);

	const isDirty = useMemo(() => {
		if (!activeSet) {
			return false;
		}
		return !filtersEqual(currentFilters, activeSet.filters);
	}, [activeSet, currentFilters]);

	useEffect(() => {
		if (!activeSavedFilterId) {
			return;
		}
		const stillExists = sets.some((set) => set.id === activeSavedFilterId);
		if (!stillExists) {
			onActiveSavedFilterIdChange(null);
		}
	}, [sets, activeSavedFilterId, onActiveSavedFilterIdChange]);

	const applySet = (set) => {
		onChangeView({
			...view,
			page: 1,
			filters: Array.isArray(set.filters) ? [...set.filters] : [],
		});
		onActiveSavedFilterIdChange(set.id);
	};

	const onSave = async () => {
		const name = newName.trim();
		if (!name || !canSave) {
			return;
		}
		try {
			const created = await createSet({
				name,
				filters: currentFilters,
			});
			setNewName('');
			onActiveSavedFilterIdChange(created.id);
			createNotice(
				'success',
				__('Saved filter created.', 'prc-wp-admin-dataview'),
				{ type: 'snackbar' }
			);
		} catch (err) {
			createNotice(
				'error',
				err?.message ||
					__('Could not save filters.', 'prc-wp-admin-dataview'),
				{ type: 'snackbar' }
			);
		}
	};

	const onUpdate = async () => {
		if (!activeSet || !isDirty) {
			return;
		}
		try {
			await updateSet(activeSet.id, { filters: currentFilters });
			createNotice(
				'success',
				__('Saved filter updated.', 'prc-wp-admin-dataview'),
				{ type: 'snackbar' }
			);
		} catch (err) {
			createNotice(
				'error',
				err?.message ||
					__(
						'Could not update saved filter.',
						'prc-wp-admin-dataview'
					),
				{ type: 'snackbar' }
			);
		}
	};

	const onConfirmDelete = async () => {
		const id = pendingDeleteId;
		setPendingDeleteId(null);
		if (!id) {
			return;
		}
		try {
			await deleteSet(id);
			if (activeSavedFilterId === id) {
				onActiveSavedFilterIdChange(null);
			}
			createNotice(
				'success',
				__('Saved filter deleted.', 'prc-wp-admin-dataview'),
				{ type: 'snackbar' }
			);
		} catch (err) {
			createNotice(
				'error',
				err?.message ||
					__(
						'Could not delete saved filter.',
						'prc-wp-admin-dataview'
					),
				{ type: 'snackbar' }
			);
		}
	};

	const onRename = async (id) => {
		const name = renameValue.trim();
		if (!name) {
			return;
		}
		try {
			await updateSet(id, { name });
			setRenamingId(null);
			setRenameValue('');
			createNotice(
				'success',
				__('Saved filter renamed.', 'prc-wp-admin-dataview'),
				{ type: 'snackbar' }
			);
		} catch (err) {
			createNotice(
				'error',
				err?.message ||
					__(
						'Could not rename saved filter.',
						'prc-wp-admin-dataview'
					),
				{ type: 'snackbar' }
			);
		}
	};

	return (
		<aside className="prc-wp-admin-dataview__saved-filters-shelf">
			<div className="prc-wp-admin-dataview__saved-filters-shelf-header">
				<h2>{__('Saved filters', 'prc-wp-admin-dataview')}</h2>
				<Button
					icon={closeSmall}
					label={__('Close saved filters', 'prc-wp-admin-dataview')}
					size="compact"
					onClick={onClose}
				/>
			</div>

			<div className="prc-wp-admin-dataview__saved-filters-shelf-body">
				<SavedFilterSetList
					sets={sets}
					activeSavedFilterId={activeSavedFilterId}
					renamingId={renamingId}
					renameValue={renameValue}
					isSaving={isSaving}
					onApply={applySet}
					onStartRename={(set) => {
						setRenamingId(set.id);
						setRenameValue(set.name);
					}}
					onRenameValueChange={setRenameValue}
					onSaveRename={onRename}
					onCancelRename={() => {
						setRenamingId(null);
						setRenameValue('');
					}}
					onRequestDelete={setPendingDeleteId}
				/>
			</div>

			<div className="prc-wp-admin-dataview__saved-filters-shelf-footer">
				{activeSet && isDirty ? (
					<Button
						variant="secondary"
						disabled={isSaving}
						onClick={onUpdate}
					>
						{__('Update saved filter', 'prc-wp-admin-dataview')}
					</Button>
				) : null}

				<div className="prc-wp-admin-dataview__saved-filters-save">
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={__('Filter name', 'prc-wp-admin-dataview')}
						value={newName}
						onChange={setNewName}
						placeholder={__(
							'Name this filter set',
							'prc-wp-admin-dataview'
						)}
						disabled={!canSave || isSaving}
					/>
					<Button
						variant="primary"
						disabled={!canSave || !newName.trim() || isSaving}
						onClick={onSave}
					>
						{__('Save current filters', 'prc-wp-admin-dataview')}
					</Button>
					{!canSave ? (
						<p className="prc-wp-admin-dataview__saved-filters-hint">
							{__(
								'Apply filters on the list before saving.',
								'prc-wp-admin-dataview'
							)}
						</p>
					) : null}
				</div>
			</div>

			{pendingDeleteId ? (
				<DeleteSavedFilterModal
					onConfirm={onConfirmDelete}
					onCancel={() => setPendingDeleteId(null)}
				/>
			) : null}
		</aside>
	);
}
