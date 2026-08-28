/**
 * Modal dismiss flags. Locked while a sequential job is in flight.
 *
 * @param {{status?: string}|null} job Bulk job.
 * @return {{isDismissible: boolean, shouldCloseOnClickOutside: boolean, shouldCloseOnEsc: boolean}} Modal dismiss props.
 */
export function getBulkModalDismissProps(job) {
	const locked = job?.status === 'processing';
	return {
		isDismissible: !locked,
		shouldCloseOnClickOutside: !locked,
		shouldCloseOnEsc: !locked,
	};
}

/**
 * Apply the same field updates to each item, one REST write at a time.
 *
 * @param {Object}   options
 * @param {Array}    options.items        Selected rows.
 * @param {Array}    options.updates      From collectDirtyUpdates.
 * @param {Function} options.updateField  (postId, field, value) => Promise
 * @param {Function} [options.onProgress] Progress callback.
 * @return {Promise<{status: 'complete', results: Array}>} Job result.
 */
export async function runBulkJob({ items, updates, updateField, onProgress }) {
	const results = [];
	const total = items.length;

	for (let index = 0; index < items.length; index++) {
		onProgress?.({
			status: 'processing',
			index: index + 1,
			total,
			results: [...results],
		});

		const item = items[index];
		let ok = true;
		let error;

		for (const update of updates) {
			try {
				await updateField(item.id, update.field, update.value);
			} catch (err) {
				ok = false;
				error =
					err?.message ||
					err?.data?.message ||
					'Could not save field.';
				break;
			}
		}

		results.push({
			postId: item.id,
			title: item.title || `#${item.id}`,
			ok,
			error,
		});
	}

	const complete = { status: 'complete', results };
	onProgress?.(complete);
	return complete;
}
