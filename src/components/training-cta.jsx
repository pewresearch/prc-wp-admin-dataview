/**
 * WordPress Dependencies
 */
import { Button } from '@wordpress/components';

/**
 * Internal Dependencies
 */
import { HeaderActionsFill } from '../slots';

/**
 * Shown instead of Add New while the builder's training gate is locked.
 *
 * @param {Object}                                                                        props
 * @param {{moduleId: string, startUrl: string, label: string, description: string}|null} props.training Boot `training` data.
 */
export default function TrainingCta({ training }) {
	if (!training?.startUrl) {
		return null;
	}

	return (
		<HeaderActionsFill>
			<span
				className="prc-wp-admin-dataview__training-hint"
				id="prc-wp-admin-dataview-training-hint"
			>
				{training.description}
			</span>
			<Button
				__next40pxDefaultSize
				variant="primary"
				href={training.startUrl}
				aria-describedby="prc-wp-admin-dataview-training-hint"
				data-prc-training-cta={training.moduleId}
			>
				{training.label}
			</Button>
		</HeaderActionsFill>
	);
}
