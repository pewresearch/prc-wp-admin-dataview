import type {
	SettingsApiResponse as BaseSettingsApiResponse,
	SettingsStoreState as BaseSettingsStoreState,
} from '@prc/components';

export interface PostTypeDescriptor {
	postType: string;
	label: string;
}

export interface Settings {
	enabled: Record<string, boolean>;
}

export interface ApiResponse extends BaseSettingsApiResponse<Settings> {
	settings: Settings;
	postTypes: PostTypeDescriptor[];
}

export interface SettingsStoreState extends BaseSettingsStoreState<Settings> {
	postTypes: PostTypeDescriptor[];
}
