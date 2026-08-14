import { createSettingsClient } from '@prc/components';
import { __ } from '@wordpress/i18n';

import { store } from './store';

export const { fetchSettings, saveSettings } = createSettingsClient({
	restPath: '/prc-wp-admin-dataview/v1/settings',
	store,
	successMessage: __('Settings saved.', 'prc-wp-admin-dataview'),
});
