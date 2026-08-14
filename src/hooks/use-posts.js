/**
 * WordPress Dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { applyFilters } from '@wordpress/hooks';
import { addQueryArgs } from '@wordpress/url';

const ORDERBY_MAP = {
	title: 'title',
	date: 'date',
	modified: 'modified',
	author: 'author',
	id: 'ID',
};

function viewToQueryArgs(view, postType) {
	const args = {
		post_type: postType,
		per_page: view.perPage || 20,
		page: view.page || 1,
		status: 'publish,draft,pending,private,future',
	};

	if (view.search) {
		args.search = view.search;
	}

	args.orderby = ORDERBY_MAP[view.sort?.field] || 'date';
	args.order = view.sort?.direction || 'desc';

	view.filters?.forEach((filter) => {
		const values = Array.isArray(filter.value)
			? filter.value
			: [filter.value];
		const joined = values
			.filter((value) => value !== undefined && value !== '')
			.join(',');

		if (!joined) {
			return;
		}

		if (filter.field === 'status') {
			args.status = joined;
		} else {
			args[filter.field] = joined;
		}
	});

	return args;
}

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

	useEffect(() => {
		if (abortRef.current) {
			abortRef.current.abort();
		}
		const controller = new AbortController();
		abortRef.current = controller;

		setIsLoading(true);
		setError(null);

		const resolvedRestPath =
			restPath || '/prc-api/v3/wp-admin-dataview/list';
		const args = applyFilters(
			'prcWpAdminDataview.restQuery',
			viewToQueryArgs(view, postType),
			{ view, postType, restPath: resolvedRestPath }
		);
		const path = addQueryArgs(resolvedRestPath, args);

		apiFetch({ path, signal: controller.signal, parse: false })
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
				setPosts(Array.isArray(data) ? data : []);
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
	}, [view, postType, restPath, refreshToken]);

	return { posts, isLoading, error, paginationInfo, refresh };
}
