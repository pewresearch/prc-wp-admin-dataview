import { SettingsPage, type SettingsFieldConfig } from '@prc/components';
import { useSelect } from '@wordpress/data';
import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { fetchSettings, saveSettings } from './api';
import { store as settingsStore } from './store';
import type { PostTypeDescriptor } from './types';

type SettingsStoreSelect = {
	getPostTypes: () => PostTypeDescriptor[];
};

function fieldsFromPostTypes(
	postTypes: PostTypeDescriptor[]
): SettingsFieldConfig[] {
	return postTypes.map((postType) => ({
		id: `enabled.${postType.postType}`,
		type: 'boolean',
		label: postType.label,
	}));
}

export default function SettingsApp() {
	const postTypes = useSelect(
		(select) =>
			(
				select(settingsStore) as unknown as SettingsStoreSelect
			).getPostTypes(),
		[]
	);
	const fields = useMemo(() => fieldsFromPostTypes(postTypes), [postTypes]);

	return (
		<SettingsPage
			title={__('Admin DataViews Settings', 'prc-wp-admin-dataview')}
			description={__(
				'Choose which post types use DataViews for their default admin list.',
				'prc-wp-admin-dataview'
			)}
			textDomain="prc-wp-admin-dataview"
			idPrefix="prc-wp-admin-dataview-settings"
			store={settingsStore}
			saveSettings={saveSettings}
			sections={[
				{
					slug: 'post-types',
					title: __('Post Types', 'prc-wp-admin-dataview'),
					description: __(
						'Turn off a post type to use its classic admin list. Changes save automatically.',
						'prc-wp-admin-dataview'
					),
					fields,
				},
			]}
			onLoad={fetchSettings}
		/>
	);
}
