import { createSettingsStore } from '@prc/components';

import type {
	ApiResponse,
	PostTypeDescriptor,
	Settings,
	SettingsStoreState,
} from './types';

export const STORE_NAME = 'prc/wp-admin-dataview-settings';

function settingsFromResponse(response: ApiResponse): Settings {
	const enabled: Record<string, boolean> = {};

	for (const postType of response.postTypes) {
		enabled[postType.postType] =
			response.settings.enabled[postType.postType] !== false;
	}

	return { enabled };
}

export const store = createSettingsStore<
	Settings,
	SettingsStoreState,
	ApiResponse
>({
	name: STORE_NAME,
	defaultState: {
		settings: {
			enabled: {},
		},
		postTypes: [],
		isLoaded: false,
	},
	getSettingsFromResponse: settingsFromResponse,
	mapResponseToState: (_state, response) => ({
		postTypes: response.postTypes,
	}),
	extraSelectors: {
		getPostTypes(state: SettingsStoreState): PostTypeDescriptor[] {
			return state.postTypes;
		},
	},
});
