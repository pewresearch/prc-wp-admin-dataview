/**
 * WordPress Dependencies
 */
import { Button, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { trash } from '@wordpress/icons';

function RenameRow({
	renameValue,
	onRenameValueChange,
	onSave,
	onCancel,
	isSaving,
}) {
	return (
		<div className="prc-wp-admin-dataview__saved-filters-rename">
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={__('Name', 'prc-wp-admin-dataview')}
				hideLabelFromVision
				value={renameValue}
				onChange={onRenameValueChange}
			/>
			<div className="prc-wp-admin-dataview__saved-filters-rename-actions">
				<Button
					variant="primary"
					size="compact"
					disabled={isSaving || !renameValue.trim()}
					onClick={onSave}
				>
					{__('Save', 'prc-wp-admin-dataview')}
				</Button>
				<Button variant="tertiary" size="compact" onClick={onCancel}>
					{__('Cancel', 'prc-wp-admin-dataview')}
				</Button>
			</div>
		</div>
	);
}

export default function SavedFilterSetList({
	sets,
	activeSavedFilterId,
	renamingId,
	renameValue,
	isSaving,
	onApply,
	onStartRename,
	onRenameValueChange,
	onSaveRename,
	onCancelRename,
	onRequestDelete,
}) {
	if (sets.length === 0) {
		return (
			<p className="prc-wp-admin-dataview__saved-filters-empty">
				{__(
					'No saved filters for this list yet.',
					'prc-wp-admin-dataview'
				)}
			</p>
		);
	}

	return (
		<ul className="prc-wp-admin-dataview__saved-filters-list">
			{sets.map((set) => {
				const isActive = set.id === activeSavedFilterId;
				const isRenaming = renamingId === set.id;
				return (
					<li
						key={set.id}
						className={`prc-wp-admin-dataview__saved-filters-item${
							isActive ? ' is-active' : ''
						}`}
					>
						{isRenaming ? (
							<RenameRow
								renameValue={renameValue}
								onRenameValueChange={onRenameValueChange}
								onSave={() => onSaveRename(set.id)}
								onCancel={onCancelRename}
								isSaving={isSaving}
							/>
						) : (
							<>
								<Button
									className="prc-wp-admin-dataview__saved-filters-apply"
									variant={isActive ? 'primary' : 'secondary'}
									onClick={() => onApply(set)}
								>
									{set.name}
								</Button>
								<div className="prc-wp-admin-dataview__saved-filters-item-actions">
									<Button
										variant="tertiary"
										size="compact"
										onClick={() => onStartRename(set)}
									>
										{__('Rename', 'prc-wp-admin-dataview')}
									</Button>
									<Button
										icon={trash}
										label={__(
											'Delete',
											'prc-wp-admin-dataview'
										)}
										size="compact"
										onClick={() => onRequestDelete(set.id)}
									/>
								</div>
							</>
						)}
					</li>
				);
			})}
		</ul>
	);
}
