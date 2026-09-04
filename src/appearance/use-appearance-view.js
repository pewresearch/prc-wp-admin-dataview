/**
 * WordPress Dependencies
 */
import { useCallback, useState } from '@wordpress/element';

/**
 * Internal Dependencies
 */
import { getDefaultView } from '../components/dataviews';
import { getFieldCapabilities } from '../fields';
import { urlHasFilterParams } from '../utils/filter-url-sync';
import {
	appearanceDocumentsEqual,
	createAppearanceSession,
	transitionAppearanceSession,
} from './model';
import {
	isNarrowAdminViewport,
	readAppearanceDocument,
	resolveAppearanceDocument,
	writeAppearanceDocument,
} from './storage';

/**
 * Own the DataViews view and persist its sparse appearance document in localStorage.
 *
 * @param {Object} options                 Hook options.
 * @param {string} options.postType        Post type.
 * @param {Object} options.config          List config.
 * @param {*}      options.initialDocument Localized appearance document.
 * @return {{view: Object, onChangeView: Function}} DataViews state.
 */
export default function useAppearanceView({
	postType,
	config,
	initialDocument,
}) {
	const [session, setSession] = useState(() => {
		const stored = readAppearanceDocument(postType);
		const resolved = resolveAppearanceDocument({
			postType,
			bootDocument: initialDocument,
			isNarrow: isNarrowAdminViewport(),
		});
		if (stored === null || !appearanceDocumentsEqual(resolved, stored)) {
			writeAppearanceDocument(postType, resolved);
		}

		return createAppearanceSession({
			defaultsView: getDefaultView(postType),
			capabilities: getFieldCapabilities({ postType, config }),
			stored: resolved,
			urlOwnsFilters: urlHasFilterParams(window.location.search),
			defaultFilters: getProviderDefaultFilters(),
		});
	});

	const onChangeView = useCallback(
		(nextViewOrUpdater) => {
			setSession((current) => {
				const result = transitionAppearanceSession(
					current,
					nextViewOrUpdater
				);
				if (Object.prototype.hasOwnProperty.call(result, 'save')) {
					writeAppearanceDocument(postType, result.save);
				}
				return result.session;
			});
		},
		[postType]
	);

	return {
		view: session.view,
		onChangeView,
	};
}

function getProviderDefaultFilters() {
	const workflows = window?.prcWpAdminDataview?.workflows?.enabled;
	if (workflows === true || workflows === 1 || workflows === '1') {
		return [
			{
				field: 'watchingOnly',
				operator: 'isAny',
				value: ['me'],
			},
		];
	}
	return [];
}
