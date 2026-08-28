/**
 * WordPress Dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

function failureLine(result) {
	if (result.ok) {
		return null;
	}
	const title = result.title || `#${result.postId}`;
	return (
		<li key={result.postId}>
			{title}: {result.error}
		</li>
	);
}

export default function BulkEditStatus({ job }) {
	if (job.status === 'processing') {
		return (
			<p>
				{sprintf(
					/* translators: 1: current item index, 2: total items */
					__('Updating %1$d of %2$d…', 'prc-wp-admin-dataview'),
					job.index,
					job.total
				)}
			</p>
		);
	}

	if (job.status !== 'complete') {
		return null;
	}

	const failed = job.results.filter((result) => !result.ok);
	if (!failed.length) {
		return (
			<p>
				{sprintf(
					/* translators: %d: number of items updated */
					__('Updated %d items.', 'prc-wp-admin-dataview'),
					job.results.length
				)}
			</p>
		);
	}

	return (
		<>
			<p>
				{sprintf(
					/* translators: 1: failure count, 2: total items */
					__(
						'%1$d of %2$d items could not be updated.',
						'prc-wp-admin-dataview'
					),
					failed.length,
					job.results.length
				)}
			</p>
			<ul>{job.results.map(failureLine)}</ul>
		</>
	);
}
