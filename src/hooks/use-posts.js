/**
 * WordPress Dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { applyFilters } from '@wordpress/hooks';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal Dependencies
 */
import { decodeRowText } from '../utils/decode-row-text';
import { viewToQueryArgs } from '../utils/view-to-query-args';

const DEFAULT_REST_PATH = '/prc-api/v3/wp-admin-dataview/list';

export default function usePosts(view, postType, restPath) {
	const [posts, setPosts] = useState([]);
	const [isLoading, setIsLoading] = useState(true);
	const [error, setError] = useState(null);
	const [paginationInfo, setPaginationInfo] = useState({
		totalItems: 0,
		totalPages: 1,
	});
	const [refreshToken, setRefreshToken] = useState(0);
	const refresh = useCallback(() => setRefreshToken((n) => n + 1), []);
	const abortRef = useRef(null);

	const resolvedRestPath = restPath || DEFAULT_REST_PATH;
	const args = applyFilters(
		'prcWpAdminDataview.restQuery',
		viewToQueryArgs(view, postType),
		{ view, postType, restPath: resolvedRestPath }
	);
	const queryKey = JSON.stringify({ path: resolvedRestPath, args });

	useEffect(() => {
		if (abortRef.current) {
			abortRef.current.abort();
		}
		const controller = new AbortController();
		abortRef.current = controller;

		setIsLoading(true);
		setError(null);

		const { path, args: requestArgs } = JSON.parse(queryKey);
		const requestPath = addQueryArgs(path, requestArgs);

		apiFetch({ path: requestPath, signal: controller.signal, parse: false })
			.then(async (response) => {
				const total = parseInt(
					response.headers.get('X-WP-Total') || '0',
					10
				);
				const totalPages = parseInt(
					response.headers.get('X-WP-TotalPages') || '1',
					10
				);
				const data = await response.json();
				setPosts(Array.isArray(data) ? data.map(decodeRowText) : []);
				setPaginationInfo({ totalItems: total, totalPages });
			})
			.catch((err) => {
				if (err.name !== 'AbortError') {
					setError(err);
					setPosts([]);
				}
			})
			.finally(() => {
				if (!controller.signal.aborted) {
					setIsLoading(false);
				}
			});

		return () => controller.abort();
	}, [queryKey, refreshToken]);

	return { posts, isLoading, error, paginationInfo, refresh };
}
