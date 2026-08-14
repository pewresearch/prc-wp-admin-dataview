/**
 * External Dependencies
 */
import { DataViews } from '@wordpress/dataviews';

/**
 * WordPress Dependencies
 */
import { Button } from '@wordpress/components';
import { useCallback, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { closeSmall } from '@wordpress/icons';

/**
 * Internal Dependencies
 */
import getActions from '../actions';
import getFields, {
	applyParentFamilyFilter,
	createItemLinkRenderer,
	getDefaultVisibleFields,
} from '../fields';
import usePosts from '../hooks/use-posts';
import usePresenceEditorsByPost from '../hooks/use-presence-editors';
import { PresenceEditorsProvider } from '../presence-context';
import {
	parseFiltersFromSearch,
	urlHasFilterParams,
} from '../utils/filter-url-sync';

// List layout is omitted on purpose: upstream DataViews does not wire
// ItemClickWrapper / renderItemLink there (selection-only interaction).
const DEFAULT_LAYOUTS = {
	table: {
		showMedia: false,
		layout: {
			primaryField: 'title',
		},
	},
	grid: {
		showMedia: true,
		mediaField: 'featuredImage',
		layout: {
			primaryField: 'title',
			previewSize: 290,
		},
	},
};

function workflowsEnabled() {
	const workflows = window?.prcWpAdminDataview?.workflows?.enabled;
	return workflows === true || workflows === 1 || workflows === '1';
}

export function getDefaultView(postType) {
	const search = window.location.search;
	let filters;

	if (urlHasFilterParams(search)) {
		filters = parseFiltersFromSearch(search);
	} else {
		filters = [];
		const postStatus = new URLSearchParams(search).get('post_status');
		if (postStatus) {
			filters.push({
				field: 'status',
				operator: 'isAny',
				value: [postStatus],
			});
		}
		if (workflowsEnabled()) {
			filters.push({
				field: 'watchingOnly',
				operator: 'isAny',
				value: ['me'],
			});
		}
	}

	return {
		type: 'table',
		page: 1,
		perPage: 20,
		sort: {
			field: 'date',
			direction: 'desc',
		},
		search: '',
		filters,
		titleField: 'title',
		mediaField: 'featuredImage',
		showMedia: false,
		fields: getDefaultVisibleFields(),
		showLevels: postType === 'post',
		layout: {
			primaryField: 'title',
		},
	};
}

export default function PostsDataViews({
	postType,
	restPath,
	config,
	view,
	onChangeView,
}) {
	const [selection, setSelection] = useState([]);
	const { posts, isLoading, error, paginationInfo, refresh } = usePosts(
		view,
		postType,
		restPath
	);
	const editorsByPostId = usePresenceEditorsByPost(postType);

	const onFilterByParent = useCallback(
		(parentId) => {
			onChangeView((current) =>
				applyParentFamilyFilter(current, parentId)
			);
		},
		[onChangeView]
	);

	const fields = useMemo(
		() => getFields({ postType, config, onFilterByParent }),
		[postType, config, onFilterByParent]
	);

	const refreshAndClearSelection = useCallback(() => {
		setSelection([]);
		refresh();
	}, [refresh]);

	const actions = useMemo(
		() =>
			getActions({
				postType,
				config,
				onRefresh: refreshAndClearSelection,
			}),
		[postType, config, refreshAndClearSelection]
	);

	const renderItemLink = useMemo(
		() =>
			createItemLinkRenderer(
				(item) => editorsByPostId?.get?.(Number(item.id)) || []
			),
		[editorsByPostId]
	);

	// Grid media a11y props (aria-labelledby / aria-label) are still gated on
	// onClickItem upstream; ItemClickWrapper prefers renderItemLink when both
	// are set, so this only unlocks naming for media anchors.
	const onClickItem = useCallback((item) => {
		if (item?.edit_url) {
			window.location.href = item.edit_url;
		}
	}, []);

	const isItemClickable = useCallback((item) => !!item?.edit_url, []);

	const getItemLevel = useCallback((item) => {
		return item?.isReportPackageChapter ? 1 : 0;
	}, []);

	const clearSelection = useCallback(() => {
		setSelection([]);
	}, []);

	const header = useMemo(() => {
		if (!selection.length) {
			return null;
		}
		return (
			<Button
				icon={closeSmall}
				label={__('Clear selection', 'prc-wp-admin-dataview')}
				size="compact"
				showTooltip
				onClick={clearSelection}
				className="prc-wp-admin-dataview__clear-selection"
			/>
		);
	}, [selection.length, clearSelection]);

	if (error) {
		return (
			<p>
				{error.message ||
					'Could not load the list. Check your permissions and try again.'}
			</p>
		);
	}

	return (
		<PresenceEditorsProvider value={editorsByPostId}>
			<div className="prc-wp-admin-dataview__dataviews">
				<DataViews
					data={posts}
					fields={fields}
					view={view}
					onChangeView={onChangeView}
					actions={actions}
					paginationInfo={paginationInfo}
					defaultLayouts={DEFAULT_LAYOUTS}
					getItemId={(item) => String(item.id)}
					getItemLevel={
						postType === 'post' ? getItemLevel : undefined
					}
					isLoading={isLoading}
					renderItemLink={renderItemLink}
					onClickItem={onClickItem}
					isItemClickable={isItemClickable}
					selection={selection}
					onChangeSelection={setSelection}
					header={header}
				/>
			</div>
		</PresenceEditorsProvider>
	);
}
