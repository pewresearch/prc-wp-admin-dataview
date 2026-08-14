/**
 * WordPress Dependencies
 */
import { useCommand } from '@wordpress/commands';
import { Button, Flex, FlexBlock, Popover } from '@wordpress/components';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { applyFilters } from '@wordpress/hooks';
import { __, sprintf } from '@wordpress/i18n';
import { filter, list } from '@wordpress/icons';

/**
 * Internal Dependencies
 */
import PostsDataViews, { getDefaultView } from './components/dataviews';
import SavedFiltersShelf from './components/saved-filters/shelf';
import useSavedFilters from './components/saved-filters/use-saved-filters';
import { HeaderActionsSlot, PageExtrasSlot } from './slots';
import { ensureFilteredFieldsVisible } from './utils/ensure-filtered-fields-visible';
import { syncFiltersToUrl } from './utils/filter-url-sync';

function closeSavedFiltersUnlessModal(onClose) {
	// Delete confirmation Modal portals outside the Popover.
	if (document.querySelector('.components-modal__screen-overlay')) {
		return;
	}
	onClose();
}

export default function App() {
	const boot = window?.prcWpAdminDataview || {};
	const postType = boot.postType || 'post';
	const classicUrl = boot.classicUrl;
	const newUrl = boot.newUrl;
	const restPath = boot.restPath;
	const pageTitle =
		boot.config?.pageTitle || __('All Items', 'prc-wp-admin-dataview');
	const pageDescription = applyFilters(
		'prcWpAdminDataview.pageDescription',
		null,
		boot
	);
	const hideDefaultNewButton =
		!!boot.hideDefaultNewButton || !!boot.config?.hideDefaultNewButton;
	const config = useMemo(
		() => ({
			...boot.config,
			enableDemoProvider: !!boot.enableDemoProvider,
		}),
		[boot.config, boot.enableDemoProvider]
	);

	const [view, setView] = useState(() =>
		ensureFilteredFieldsVisible(getDefaultView(postType))
	);
	const [shelfOpen, setShelfOpen] = useState(false);
	const [activeSavedFilterId, setActiveSavedFilterId] = useState(null);
	const initialSavedFilters = Array.isArray(boot.savedFilters)
		? boot.savedFilters
		: [];
	const { sets, isSaving, createSet, updateSet, deleteSet } = useSavedFilters(
		postType,
		initialSavedFilters
	);

	const closeShelf = useCallback(() => setShelfOpen(false), []);

	useEffect(() => {
		syncFiltersToUrl(view.filters);
		// Mount-only: seed shareable URL before the first filter edit.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, []);

	const onChangeView = useCallback((next) => {
		setView((current) => {
			const resolved = typeof next === 'function' ? next(current) : next;
			const withColumns = ensureFilteredFieldsVisible(resolved);
			syncFiltersToUrl(withColumns.filters);
			return withColumns;
		});
	}, []);

	const singularLabel = (
		boot.singularLabel ||
		boot.config?.singularLabel ||
		postType ||
		'post'
	).toLowerCase();

	useCommand({
		name: 'prc-wp-admin-dataview/switch-to-classic-list',
		label: sprintf(
			/* translators: %s: lowercase post type singular name, e.g. "post" or "quiz". */
			__('Switch to classic %s table', 'prc-wp-admin-dataview'),
			singularLabel
		),
		icon: list,
		category: 'site',
		keywords: ['classic', 'list table', postType],
		disabled: !classicUrl,
		callback: ({ close }) => {
			window.location.assign(classicUrl);
			close();
		},
	});

	return (
		<div className="prc-wp-admin-dataview">
			<div className="prc-wp-admin-dataview__layout">
				<div className="prc-wp-admin-dataview__main">
					<header className="prc-wp-admin-dataview__header">
						<Flex align="center" gap={3}>
							<FlexBlock>
								<div className="prc-wp-admin-dataview__title-row">
									<span className="prc-wp-admin-dataview__saved-filters-toggle-wrap">
										<Button
											className="prc-wp-admin-dataview__saved-filters-toggle"
											icon={filter}
											label={__(
												'Saved filters',
												'prc-wp-admin-dataview'
											)}
											size="compact"
											isPressed={shelfOpen}
											aria-expanded={shelfOpen}
											onClick={() =>
												setShelfOpen((open) => !open)
											}
										/>
										{sets.length > 0 ? (
											<span
												className="prc-wp-admin-dataview__saved-filters-badge"
												aria-hidden="true"
											>
												{sets.length}
											</span>
										) : null}
										{shelfOpen ? (
											<Popover
												className="prc-wp-admin-dataview__saved-filters-popover"
												placement="bottom-start"
												offset={8}
												focusOnMount="firstElement"
												onClose={closeShelf}
												onFocusOutside={() =>
													closeSavedFiltersUnlessModal(
														closeShelf
													)
												}
											>
												<SavedFiltersShelf
													view={view}
													onChangeView={onChangeView}
													activeSavedFilterId={
														activeSavedFilterId
													}
													onActiveSavedFilterIdChange={
														setActiveSavedFilterId
													}
													onClose={closeShelf}
													sets={sets}
													isSaving={isSaving}
													createSet={createSet}
													updateSet={updateSet}
													deleteSet={deleteSet}
												/>
											</Popover>
										) : null}
									</span>
									<h1>{pageTitle}</h1>
								</div>
								{pageDescription ? (
									<div className="prc-wp-admin-dataview__description">
										{pageDescription}
									</div>
								) : null}
							</FlexBlock>
							{newUrl && !hideDefaultNewButton ? (
								<Button variant="primary" href={newUrl}>
									{__('Add New', 'prc-wp-admin-dataview')}
								</Button>
							) : null}
							<HeaderActionsSlot />
						</Flex>
					</header>
					<PostsDataViews
						postType={postType}
						restPath={restPath}
						config={config}
						view={view}
						onChangeView={onChangeView}
					/>
					<PageExtrasSlot />
				</div>
			</div>
		</div>
	);
}
