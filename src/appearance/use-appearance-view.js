/**
 * WordPress Dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

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

const REST_PATH = '/prc-api/v3/wp-admin-dataview/appearance';
const SAVE_DELAY = 800;

/**
 * Own the DataViews view and persist its sparse appearance document.
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
	const [session, setSession] = useState(() =>
		createAppearanceSession({
			defaultsView: getDefaultView(postType),
			capabilities: getFieldCapabilities({ postType, config }),
			stored: initialDocument,
			urlOwnsFilters: urlHasFilterParams(window.location.search),
			defaultFilters: getProviderDefaultFilters(),
		})
	);
	const timerRef = useRef(null);
	const pendingRef = useRef(null);
	const hasPendingRef = useRef(false);
	const inFlightRef = useRef(false);
	const lastSentRef = useRef(session.document);
	const lastQueuedRef = useRef(session.document);
	const flushRef = useRef(null);
	const issuedAtRef = useRef(0);

	const flush = useCallback(
		(options = {}) => {
			const ignoreInFlight = options.ignoreInFlight === true;
			if (timerRef.current) {
				clearTimeout(timerRef.current);
				timerRef.current = null;
			}
			if (!hasPendingRef.current) {
				return;
			}
			if (inFlightRef.current && !ignoreInFlight) {
				return;
			}

			const document = pendingRef.current;
			if (appearanceDocumentsEqual(document, lastSentRef.current)) {
				hasPendingRef.current = false;
				return;
			}

			hasPendingRef.current = false;
			inFlightRef.current = true;
			// Monotonic token so an older overlapping keepalive PUT cannot win.
			const issuedAt = Math.max(Date.now(), issuedAtRef.current + 1);
			issuedAtRef.current = issuedAt;
			apiFetch({
				path: REST_PATH,
				method: 'PUT',
				// Keep the PUT alive across navigation; browsers cancel ordinary
				// fetches when the document unloads.
				keepalive: true,
				data: {
					post_type: postType,
					document,
					issued_at: issuedAt,
				},
			})
				.then(() => {
					if (issuedAt !== issuedAtRef.current) {
						return;
					}
					lastSentRef.current = document;
				})
				.catch(() => {
					if (issuedAt !== issuedAtRef.current) {
						return;
					}
					if (
						appearanceDocumentsEqual(
							lastQueuedRef.current,
							document
						)
					) {
						lastQueuedRef.current = lastSentRef.current;
						pendingRef.current = document;
						hasPendingRef.current = true;
					}
				})
				.finally(() => {
					if (issuedAt !== issuedAtRef.current) {
						return;
					}
					inFlightRef.current = false;
					if (
						hasPendingRef.current &&
						!appearanceDocumentsEqual(pendingRef.current, document)
					) {
						flushRef.current();
					}
				});
		},
		[postType]
	);
	flushRef.current = flush;

	const queueSave = useCallback((document) => {
		if (appearanceDocumentsEqual(document, lastQueuedRef.current)) {
			return;
		}

		lastQueuedRef.current = document;
		pendingRef.current = document;
		hasPendingRef.current = true;
		if (timerRef.current) {
			clearTimeout(timerRef.current);
		}
		timerRef.current = setTimeout(() => {
			flushRef.current?.();
		}, SAVE_DELAY);
	}, []);

	useEffect(() => {
		const flushPending = () => {
			flushRef.current?.({ ignoreInFlight: true });
		};
		window.addEventListener('pagehide', flushPending);
		return () => {
			window.removeEventListener('pagehide', flushPending);
			flushPending();
		};
	}, []);

	const onChangeView = useCallback(
		(nextViewOrUpdater) => {
			setSession((current) => {
				const result = transitionAppearanceSession(
					current,
					nextViewOrUpdater
				);
				if (Object.prototype.hasOwnProperty.call(result, 'save')) {
					queueSave(result.save);
				}
				return result.session;
			});
		},
		[queueSave]
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
