/**
 * External Dependencies
 */
import { DataViews } from '@wordpress/dataviews';

/**
 * WordPress Dependencies
 */
import { Button } from '@wordpress/components';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { closeSmall } from '@wordpress/icons';

/**
 * Internal Dependencies
 */
import getActions from '../actions';
import { getDefaultLayoutType } from '../appearance/storage';
import getFields, {
	applyParentFamilyFilter,
	createItemLinkRenderer,
	getDefaultVisibleFields,
	isLocalizedFlag,
} from '../fields';
import usePosts from '../hooks/use-posts';
import usePresenceEditorsByPost from '../hooks/use-presence-editors';
import { PresenceEditorsProvider } from '../presence-context';
import {
	parseFiltersFromSearch,
	urlHasFilterParams,
} from '../utils/filter-url-sync';
import { isCollectionList } from '../utils/list-kind';
import { getEditableFields } from '../utils/simple-edit-fields';
import BulkEditModal from './bulk-edit-modal';

export const REFRESH_EVENT = 'prcWpAdminDataview.refresh';

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
	list: {
		showMedia: true,
		mediaField: 'featuredImage',
		layout: {
			primaryField: 'title',
		},
	},
};

const COLLECTION_LAYOUTS = {
	table: {
		showMedia: false,
		layout: {
			primaryField: 'title',
		},
	},
	list: {
		showMedia: false,
		layout: {
			primaryField: 'title',
		},
	},
};

const DEFAULT_SORT = { field: 'date', direction: 'desc' };
const COLLECTION_SORT = { field: 'title', direction: 'asc' };

function getDefaultSort() {
	if (!isCollectionList()) {
		return DEFAULT_SORT;
	}
	const sort = window?.prcWpAdminDataview?.config?.defaultSort;
	return typeof sort?.field === 'string' &&
		(sort.direction === 'asc' || sort.direction === 'desc')
		? { field: sort.field, direction: sort.direction }
		: COLLECTION_SORT;
}

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
		type: getDefaultLayoutType(),
		page: 1,
		perPage: 20,
		sort: getDefaultSort(),
		search: '',
		filters,
		titleField: 'title',
		mediaField: isCollectionList() ? undefined : 'featuredImage',
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
	const [bulkItems, setBulkItems] = useState(null);
	const { posts, isLoading, error, paginationInfo, refresh } = usePosts(
		view,
		postType,
		restPath
	);
	const isCollection = isCollectionList();
	const editorsByPostId = usePresenceEditorsByPost(
		isCollection ? '' : postType
	);

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

	const canPublish = isLocalizedFlag(window?.prcWpAdminDataview?.canPublish);
	const editableFields = useMemo(
		() => getEditableFields(fields, { canPublish }),
		[fields, canPublish]
	);

	const refreshAndClearSelection = useCallback(() => {
		setSelection([]);
		refresh();
	}, [refresh]);

	useEffect(() => {
		window.addEventListener(REFRESH_EVENT, refreshAndClearSelection);
		return () =>
			window.removeEventListener(REFRESH_EVENT, refreshAndClearSelection);
	}, [refreshAndClearSelection]);

	useEffect(() => {
		const intervalMs = Number(config?.refreshIntervalMs);
		if (!Number.isFinite(intervalMs) || intervalMs < 1000) {
			return undefined;
		}
		const interval = window.setInterval(refresh, intervalMs);
		return () => window.clearInterval(interval);
	}, [config?.refreshIntervalMs, refresh]);

	const handleBulkEdit = useCallback((items) => {
		setBulkItems(items);
	}, []);

	const actions = useMemo(
		() =>
			getActions({
				postType,
				config,
				onRefresh: refreshAndClearSelection,
				onBulkEdit: handleBulkEdit,
				hasEditableFields: editableFields.length > 0,
			}),
		[
			postType,
			config,
			refreshAndClearSelection,
			handleBulkEdit,
			editableFields.length,
		]
	);

	const renderItemLink = useMemo(
		() =>
			createItemLinkRenderer(
				(item) => editorsByPostId?.get?.(Number(item.id)) || []
			),
		[editorsByPostId]
	);

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
					defaultLayouts={
						isCollection ? COLLECTION_LAYOUTS : DEFAULT_LAYOUTS
					}
					getItemId={(item) => String(item.id)}
					getItemLevel={
						postType === 'post' ? getItemLevel : undefined
					}
					isLoading={isLoading}
					empty={
						<p>
							{__(
								'No items match this search.',
								'prc-wp-admin-dataview'
							)}
						</p>
					}
					renderItemLink={renderItemLink}
					onClickItem={onClickItem}
					isItemClickable={isItemClickable}
					selection={selection}
					onChangeSelection={setSelection}
					header={header}
				/>
			</div>
			{bulkItems?.length ? (
				<BulkEditModal
					items={bulkItems}
					editableFields={editableFields}
					postType={postType}
					onClose={() => setBulkItems(null)}
					onComplete={refreshAndClearSelection}
				/>
			) : null}
		</PresenceEditorsProvider>
	);
}
