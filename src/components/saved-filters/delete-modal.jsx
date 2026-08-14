/**
 * WordPress Dependencies
 */
import { Button, Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function DeleteSavedFilterModal({ onConfirm, onCancel }) {
	return (
		<Modal
			title={__('Delete saved filter', 'prc-wp-admin-dataview')}
			onRequestClose={onCancel}
		>
			<p>
				{__(
					'Delete this saved filter? This cannot be undone.',
					'prc-wp-admin-dataview'
				)}
			</p>
			<div className="prc-wp-admin-dataview__saved-filters-rename-actions">
				<Button variant="primary" isDestructive onClick={onConfirm}>
					{__('Delete', 'prc-wp-admin-dataview')}
				</Button>
				<Button variant="tertiary" onClick={onCancel}>
					{__('Cancel', 'prc-wp-admin-dataview')}
				</Button>
			</div>
		</Modal>
	);
}
