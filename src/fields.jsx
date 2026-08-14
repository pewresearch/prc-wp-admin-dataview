/**
 * WordPress Dependencies
 */
import { TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { applyFilters } from '@wordpress/hooks';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal Dependencies
 */
import { usePresenceEditorsContext } from './presence-context';

const MAX_VISIBLE_AVATARS = 3;

function getLocalizedData() {
	return window?.prcWpAdminDataview || {};
}

/**
 * wp_localize_script stringifies booleans (`true` → `"1"`).
 *
 * @param {boolean|number|string} value Localized flag value.
 * @return {boolean} Whether the flag is truthy.
 */
export function isLocalizedFlag(value) {
	return value === true || value === 1 || value === '1';
}

export function supportsParentFamily(postType) {
	const boot = getLocalizedData();
	return (
		isLocalizedFlag(boot.supportsParentFamily) ||
		postType === 'post' ||
		boot.postType === 'post'
	);
}

const FAMILY_FIELD = 'parentFamily';
const ENUM_FIELD = 'parentPost';

const PARENT_POST_ELEMENTS = [
	{
		value: 'parent_posts',
		label: __('Parent Posts', 'prc-wp-admin-dataview'),
	},
	{
		value: 'child_posts',
		label: __('Child Posts', 'prc-wp-admin-dataview'),
	},
];

export function withoutParentFilters(filters = []) {
	return filters.filter(
		(filter) => filter.field !== FAMILY_FIELD && filter.field !== ENUM_FIELD
	);
}

export function applyParentFamilyFilter(view, parentId) {
	const parsed = Number.parseInt(parentId, 10);
	const nextFilters = withoutParentFilters(view.filters);

	if (Number.isInteger(parsed) && parsed > 0) {
		nextFilters.push({
			field: FAMILY_FIELD,
			operator: 'is',
			value: parsed,
		});
	}

	return {
		...view,
		page: 1,
		filters: nextFilters,
	};
}

function getStatusElements() {
	return (getLocalizedData().statuses || []).map((option) => ({
		value: option.value,
		label: option.label,
	}));
}

function getStatusLabel(value) {
	const match = (getLocalizedData().statuses || []).find(
		(option) => option.value === value
	);
	return match?.label || value || '—';
}

function getTaxonomyEntries() {
	const taxonomies = getLocalizedData().taxonomies;
	if (!taxonomies || typeof taxonomies !== 'object') {
		return [];
	}
	return Object.entries(taxonomies).map(([fieldId, config]) => ({
		fieldId,
		label: config?.label || fieldId,
		elements: Array.isArray(config?.elements) ? config.elements : [],
		defaultVisible: Boolean(config?.defaultVisible),
	}));
}

function getTaxonomyLabel(item, fieldId) {
	const value = item?.[fieldId];
	return typeof value === 'string' ? value : '';
}

function stopRowNav(event) {
	event?.stopPropagation?.();
}

function TitleEdit({ data, field, onChange, hideLabelFromVision }) {
	const [value, setValue] = useState(field.getValue({ item: data }) || '');

	return (
		<div onClick={stopRowNav} onKeyDown={stopRowNav} role="presentation">
			<TextControl
				label={field.label}
				hideLabelFromVision={hideLabelFromVision}
				value={value}
				onChange={(nextValue) => {
					setValue(nextValue);
					onChange(field.setValue({ item: data, value: nextValue }));
				}}
			/>
		</div>
	);
}

function PresenceStack({ editors }) {
	if (!editors?.length) {
		return null;
	}

	const visible = editors.slice(0, MAX_VISIBLE_AVATARS);
	const overflow = editors.length - visible.length;
	const names = editors.map((editor) => editor.displayName).join(', ');
	const ariaLabel = sprintf(
		/* translators: %s: comma-separated editor display names */
		_n(
			'%s is editing',
			'%s are editing',
			editors.length,
			'prc-wp-admin-dataview'
		),
		names
	);

	return (
		<span
			className="prc-wp-admin-dataview__presence-stack"
			aria-label={ariaLabel}
			title={names}
			role="img"
		>
			{visible.map((editor, index) =>
				editor.avatarUrl ? (
					<img
						key={editor.userId}
						src={editor.avatarUrl}
						alt=""
						width={24}
						height={24}
						title={editor.displayName}
						style={{ zIndex: visible.length - index }}
					/>
				) : (
					<span
						key={editor.userId}
						className="prc-wp-admin-dataview__presence-fallback"
						title={editor.displayName}
						style={{ zIndex: visible.length - index }}
					>
						{(editor.displayName || '?').charAt(0)}
					</span>
				)
			)}
			{overflow > 0 ? (
				<span
					className="prc-wp-admin-dataview__presence-overflow"
					style={{ zIndex: 0 }}
				>
					{sprintf(
						/* translators: %d: number of additional editors */
						__('+%d', 'prc-wp-admin-dataview'),
						overflow
					)}
				</span>
			) : null}
		</span>
	);
}

function TitleCell({ item }) {
	const editorsByPostId = usePresenceEditorsContext();
	const editors = editorsByPostId?.get?.(Number(item.id)) || [];
	const title = item.title || __('(no title)', 'prc-wp-admin-dataview');
	const isChild = Number(item.parentPostId) > 0;
	const titleClassName = `prc-wp-admin-dataview__title-text${
		isChild ? ' prc-wp-admin-dataview__child-title-text' : ''
	}`;

	// Rows with edit_url use renderItemLink, which places PresenceStack outside
	// the edit <a>. Keep presence here only for non-clickable rows.
	if (item.edit_url) {
		return <span className={titleClassName}>{title}</span>;
	}

	return (
		<div className="prc-wp-admin-dataview__title-cell">
			<span className={titleClassName}>{title}</span>
			<PresenceStack editors={editors} />
		</div>
	);
}

/**
 * Build a DataViews `renderItemLink` that keeps presence avatars outside the
 * edit anchor (ItemClickWrapper wraps the full title field render output).
 *
 * @param {(item: Object) => Array} getEditors Editors for a list row.
 * @return {(props: Object) => Object} renderItemLink callback.
 */
export function createItemLinkRenderer(getEditors) {
	return function renderItemLink({ item, children, className, ...props }) {
		const isTitleLink =
			typeof className === 'string' &&
			className.includes('dataviews-title-field');

		if (!isTitleLink) {
			return (
				<a href={item.edit_url} className={className} {...props}>
					{children}
				</a>
			);
		}

		return (
			<span className="prc-wp-admin-dataview__title-cell">
				<a href={item.edit_url} className={className} {...props}>
					{children}
				</a>
				<PresenceStack editors={getEditors(item) || []} />
			</span>
		);
	};
}

function FeaturedImageField({ item }) {
	const url = item?.featuredImage || '';

	if (url) {
		return (
			<img
				className="prc-wp-admin-dataview__featured-image"
				src={url}
				alt=""
				loading="lazy"
				decoding="async"
			/>
		);
	}

	return (
		<div
			className="prc-wp-admin-dataview__featured-image prc-wp-admin-dataview__featured-image--empty"
			aria-hidden="true"
		/>
	);
}

export function getDefaultVisibleFields() {
	const boot = getLocalizedData();
	// Omit `title`: it is view.titleField (primary column). Including it here
	// duplicates the Title column in the table.
	const fields = ['status', 'author', 'date'];
	for (const entry of getTaxonomyEntries()) {
		if (entry.defaultVisible && !fields.includes(entry.fieldId)) {
			fields.push(entry.fieldId);
		}
	}
	return applyFilters(
		'prcWpAdminDataview.defaultVisibleFields',
		fields,
		boot
	);
}

export default function getFields({
	postType,
	config,
	updateField,
	onRefresh,
	onFilterByParent,
}) {
	const baseFields = [
		{
			id: 'featuredImage',
			label: __('Featured Image', 'prc-wp-admin-dataview'),
			enableSorting: false,
			enableHiding: true,
			filterBy: false,
			getValue: ({ item }) => item.featuredImage || '',
			render: ({ item }) => <FeaturedImageField item={item} />,
		},
		{
			id: 'title',
			label: __('Title', 'prc-wp-admin-dataview'),
			type: 'text',
			enableHiding: false,
			enableSorting: true,
			enableGlobalSearch: true,
			getValue: ({ item }) => item.title || '',
			setValue: ({ item, value }) => {
				updateField?.(item.id, 'title', value).then(() => {
					onRefresh?.();
				});
				return { title: value };
			},
			Edit: TitleEdit,
			render: ({ item }) => <TitleCell item={item} />,
		},
		{
			id: 'status',
			label: __('Status', 'prc-wp-admin-dataview'),
			type: 'text',
			elements: getStatusElements(),
			filterBy: {
				operators: ['isAny', 'isNone'],
				isPrimary: true,
			},
			enableSorting: false,
			getValue: ({ item }) => item.status || '',
			render: ({ item }) => <span>{getStatusLabel(item.status)}</span>,
		},
		{
			id: 'author',
			label: __('Author', 'prc-wp-admin-dataview'),
			type: 'text',
			enableSorting: true,
			getValue: ({ item }) => item.author || '',
		},
		{
			id: 'date',
			label: __('Date', 'prc-wp-admin-dataview'),
			type: 'datetime',
			enableSorting: true,
			getValue: ({ item }) => item.date || '',
			render: ({ item }) => {
				if (!item.date) {
					return '—';
				}
				return new Date(item.date).toLocaleString();
			},
		},
	];

	if (supportsParentFamily(postType)) {
		baseFields.push({
			id: 'parentPost',
			label: __('Parent', 'prc-wp-admin-dataview'),
			type: 'text',
			enableSorting: false,
			elements: postType === 'post' ? PARENT_POST_ELEMENTS : undefined,
			filterBy:
				postType === 'post'
					? { operators: ['is'], isPrimary: true }
					: false,
			getValue: ({ item }) => item.parentPostTitle || '',
			render: ({ item }) => {
				if (!item.parentPostId) {
					return __('None', 'prc-wp-admin-dataview');
				}
				const label = item.parentPostTitle || `#${item.parentPostId}`;
				if (!onFilterByParent) {
					return label;
				}
				return (
					<button
						type="button"
						className="prc-wp-admin-dataview__parent-filter-link"
						onClick={(event) => {
							stopRowNav(event);
							onFilterByParent(item.parentPostId);
						}}
						onKeyDown={stopRowNav}
					>
						{label}
					</button>
				);
			},
		});

		baseFields.push({
			id: 'parentFamily',
			label: __('Parent ID', 'prc-wp-admin-dataview'),
			type: 'integer',
			enableSorting: false,
			enableHiding: false,
			filterBy: { operators: ['is'] },
			format: { separatorThousand: '' },
			getValue: ({ item }) => item.parentPostId ?? 0,
		});
	}

	if (getLocalizedData().presence?.enabled) {
		baseFields.push({
			id: 'activeEditors',
			label: __('Active editors', 'prc-wp-admin-dataview'),
			elements: [
				{
					value: 'active',
					label: __(
						'Currently being edited',
						'prc-wp-admin-dataview'
					),
				},
			],
			filterBy: {
				operators: ['isAny'],
				isPrimary: true,
			},
			enableSorting: false,
			enableHiding: true,
			getValue: () => '',
			render: () => null,
		});
	}

	for (const entry of getTaxonomyEntries()) {
		baseFields.push({
			id: entry.fieldId,
			label: entry.label,
			elements: entry.elements,
			filterBy:
				entry.elements.length > 0
					? {
							operators: ['isAny'],
							isPrimary: true,
						}
					: false,
			enableSorting: false,
			getValue: ({ item }) => getTaxonomyLabel(item, entry.fieldId),
			render: ({ item }) => getTaxonomyLabel(item, entry.fieldId) || '—',
		});
	}

	baseFields.push({
		id: 'id',
		label: __('ID', 'prc-wp-admin-dataview'),
		type: 'integer',
		enableSorting: true,
		getValue: ({ item }) => item.id,
	});

	return applyFilters('prcWpAdminDataview.fields', baseFields, {
		postType,
		config,
	});
}
