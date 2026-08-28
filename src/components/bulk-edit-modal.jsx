/**
 * WordPress Dependencies
 */
import { useCallback, useMemo, useState } from '@wordpress/element';
import { Modal } from '@wordpress/components';
import { DataForm } from '@wordpress/dataviews/wp';
import { __ } from '@wordpress/i18n';

/**
 * Internal Dependencies
 */
import { updateFieldRequest } from '../hooks/use-update-field';
import {
	collectDirtyUpdates,
	createBulkDraft,
	toBulkFormFields,
} from '../utils/simple-edit-fields';
import { getBulkModalDismissProps, runBulkJob } from '../utils/run-bulk-job';
import BulkEditFooter from './bulk-edit-footer';
import BulkEditStatus from './bulk-edit-status';

const IDLE_JOB = { status: 'idle' };

export default function BulkEditModal({
	items,
	editableFields,
	postType,
	onClose,
	onComplete,
}) {
	const formFields = useMemo(
		() => toBulkFormFields(editableFields),
		[editableFields]
	);
	const initial = useMemo(
		() => createBulkDraft(editableFields),
		[editableFields]
	);
	const [data, setData] = useState(initial);
	const [job, setJob] = useState(IDLE_JOB);

	const dirty = useMemo(
		() => collectDirtyUpdates(initial, data, editableFields),
		[initial, data, editableFields]
	);
	const processing = job.status === 'processing';
	const showForm = job.status === 'idle';

	const handleSubmit = useCallback(async () => {
		if (!dirty.length || processing) {
			return;
		}
		setJob({
			status: 'processing',
			index: 1,
			total: items.length,
			results: [],
		});
		const complete = await runBulkJob({
			items,
			updates: dirty,
			updateField: (postId, field, value) =>
				updateFieldRequest(postType, postId, field, value),
			onProgress: setJob,
		});
		setJob(complete);
	}, [dirty, items, postType, processing]);

	const handleClose = useCallback(() => {
		if (processing) {
			return;
		}
		const done = job.status === 'complete';
		onClose();
		if (done) {
			onComplete();
		}
	}, [job.status, onClose, onComplete, processing]);

	return (
		<Modal
			title={__('Bulk edit', 'prc-wp-admin-dataview')}
			onRequestClose={handleClose}
			className="prc-wp-admin-dataview__bulk-edit-modal"
			{...getBulkModalDismissProps(job)}
		>
			<BulkEditStatus job={job} />
			{showForm && (
				<DataForm
					data={data}
					fields={formFields}
					form={{
						layout: { type: 'regular' },
						fields: formFields.map((field) => field.id),
					}}
					onChange={(updates) =>
						setData((current) => ({ ...current, ...updates }))
					}
				/>
			)}
			<BulkEditFooter
				job={job}
				canSubmit={dirty.length > 0}
				onClose={handleClose}
				onSubmit={handleSubmit}
			/>
		</Modal>
	);
}
