/**
 * WordPress Dependencies
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function BulkEditFooter({ job, canSubmit, onClose, onSubmit }) {
	const processing = job.status === 'processing';
	const complete = job.status === 'complete';

	return (
		<div className="prc-wp-admin-dataview__bulk-edit-footer">
			{complete ? (
				<Button variant="primary" onClick={onClose}>
					{__('Close', 'prc-wp-admin-dataview')}
				</Button>
			) : (
				<>
					<Button
						variant="tertiary"
						onClick={onClose}
						disabled={processing}
					>
						{__('Cancel', 'prc-wp-admin-dataview')}
					</Button>
					<Button
						variant="primary"
						onClick={onSubmit}
						disabled={!canSubmit || processing}
					>
						{__('Update', 'prc-wp-admin-dataview')}
					</Button>
				</>
			)}
		</div>
	);
}
