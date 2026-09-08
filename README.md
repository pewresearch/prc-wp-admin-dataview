# PRC WP Admin Dataview

Shared DataViews admin list shells for core and shared post types, plus a field-provider contract for domain plugins.

## What it does

- Replaces the classic `edit.php` list for `post` and `page` with a DataViews screen by default.
- Lets editors switch among table, grid, and list layouts. The last layout persists per browser and post type (localStorage).
- Mounts that screen through Gutenberg's `@wordpress/boot` `initSinglePage` runtime when the Boot script module is available (wp-admin chrome stays; Boot owns the stage layout, router `?p=`, and snackbars). Do not npm-install or webpack-bundle `@wordpress/boot`; consume Gutenberg's registered script module only. If Boot is missing, the classic bundle still `createRoot`s `#prc-wp-admin-dataview`.
- Keeps `?classic=1` so bulk actions, screen options, Empty Trash, and Quick Edit stay reachable. On DataViews list screens, open the WordPress command palette (Ctrl/Cmd+K) and run **Switch to classic {type} table** (for example **Switch to classic post table**).
- Renders the Status column as stop-light badges (Gutenberg PostStatus icons or a colored dot, plus matching label) so editors can scan Published vs Draft at a glance.
- Shows active editor avatars and can filter to posts that are currently being edited when the Presence API supports the post type.
- Ships a Parent Post column + parent/child filter on the posts list.
- Lets other plugins enrich rows, query args, localized filter metadata, fields, and actions (SEO, attachments report, social packages).
- Lets editors bulk-edit selected rows through a shell-owned modal. Writes go one field at a time through `POST /prc-api/v3/wp-admin-dataview/field`.

## Bulk editing

A field is editable only when all of these hold:

1. `readOnly` is not `true`.
2. It declares `type`, or `Edit` is `'textarea'` / `{ control: 'textarea' }`.
3. The inferred control is one of `text`, `textarea`, `integer`, or `select`.
4. `Edit` is not a custom function (parent filter button, media, entity search).

Inference:

- Non-empty `elements` plus `type` → `select`
- `boolean` → `select` of Yes / No
- `text` / `email` / `url` / `telephone` → `text`
- `Edit: 'textarea'` or `{ control: 'textarea' }` → `textarea`
- `integer` / `number` → `integer`
- `datetime`, `date`, `media`, `color`, `array`, `password`, custom `Edit` → omit

`type` is the opt-in. Fields that only have `elements` for filters (taxonomies, chart type) stay display/filter until a provider adds `type` **and** a PHP `update_field` handler. Display-only typed fields must set `readOnly: true`.

Bulk edit is a selection action. Cells stay display-only and the title stays a row link. The modal cannot be dismissed while a job runs (`isDismissible` / click-outside / Escape are locked). Writes are sequential (`await` each `POST .../field`; never `Promise.all`). Item errors are recorded and the job continues.

Do not add domain “edit fields” row-action modals for simple typed fields. Keep PHP `update_field` handlers; the shell bulk UI covers the rest.

The shell writes `title` and `status` (status allowlist excludes `trash` and `future`). Domain plugins still win on their field ids when they return non-null first.

## Provider contract

### PHP filters

| Filter                                  | Signature                                                                                      | Purpose                                                                                                                                                                               |
| --------------------------------------- | ---------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `prc_wp_admin_dataview_shape_row`       | `(array $row, WP_Post $post, string $post_type): array`                                        | Add fields to each list row                                                                                                                                                           |
| `prc_wp_admin_dataview_query_args`      | `(array $args, WP_REST_Request $request, string $post_type): array`                            | Map DataViews filters to `WP_Query`                                                                                                                                                   |
| `prc_wp_admin_dataview_localize`        | `(array $data, string $post_type): array`                                                      | Pass filter elements / flags to JS                                                                                                                                                    |
| `prc_wp_admin_dataview_update_field`    | `(true\|WP_Error\|null $result, int $post_id, string $field, mixed $value, string $post_type)` | Handle a field write; first non-null wins                                                                                                                                             |
| `prc_wp_admin_dataview_duplicate_args`  | `(array $args, string $post_type, WP_Post\|null $source): array`                               | Change include/exclude lists, suffix, or enabled after list config                                                                                                                    |
| `prc_wp_admin_dataview_taxonomy_fields` | `(array $registry): array`                                                                     | Add `{taxonomy, fieldId, label, defaultVisible, isPrimaryFilter}` rows. The shell lazy-loads term options, filters, and shapes those taxonomies when the current post type uses them. |

Constants live on `PRC\Platform\Wp_Admin_Dataview\Provider_Registry`.

### JS hooks

| Hook                                      | Signature                                               | Purpose                                                  |
| ----------------------------------------- | ------------------------------------------------------- | -------------------------------------------------------- |
| `prcWpAdminDataview.fields`               | `(fields, { postType, config }) => fields`              | Add / edit DataViews field defs                          |
| `prcWpAdminDataview.actions`              | `(actions, { postType, config, onRefresh }) => actions` | Add or remap row actions. Do not replace the array.      |
| `prcWpAdminDataview.defaultVisibleFields` | `(fields, boot) => fields`                              | Set the initial visible field IDs                        |
| `prcWpAdminDataview.pageDescription`      | `(description, boot) => ReactNode`                      | Add content below the page title                         |
| `prcWpAdminDataview.restQuery`            | `(args, { view, postType, restPath }) => args`          | Map domain field IDs and sorting to REST query arguments |

`prcWpAdminDataview.actions` **adds or remaps** incoming shell actions (quiz remaps labels; email drops View on transactional lists). The shell already ships Duplicate after Edit. **Do not replace** the array. Replacing discards shell Edit (real link), Duplicate, View, Trash (bulk + notices), and later shared actions.

Row navigation uses DataViews `renderItemLink` with `item.edit_url`. Prefer that over `onClickItem` on list screens. Keep `onClickItem` only for pickers and modals that select a value instead of navigating.

### JS SlotFills

The shell provides two named slots. Boot's `RootSinglePage` wraps `SlotFillProvider` (the classic fallback does not add a second provider):

- `prcWpAdminDataview.HeaderActions` renders next to the default Add New button.
- `prcWpAdminDataview.PageExtras` renders after the list body and is intended for domain modals.

The shell entry exports `HeaderActionsFill`, `PageExtrasFill`, `emitPageExtra`, and `subscribePageExtra`. A domain bundle can also call `createSlotFill()` with the exact names above. Provider scripts must depend on the `prc-wp-admin-dataview` script handle.

### REST

- `GET /prc-api/v3/wp-admin-dataview/list` — paginated rows. A non-empty `search` param is applied **after** `prc_wp_admin_dataview_query_args` (`s`, `ep_integrate`, numeric `post__in`). Trash-only status lists, chart lists, and any `meta_query` filter (Working on / watchers, dataset ZIP, quiz type, form action, email status) stay on MySQL. Chart search matches **title** and `design_slug` only (not block `post_content`). Default date sort becomes relevance so title hits rank first. When Elasticsearch is down, ElasticPress falls back to MySQL. An `author` param of user IDs maps to `WP_Query` `author` / `author__in`.
- `GET /prc-api/v3/wp-admin-dataview/terms?taxonomy=&post_type=` — `{ value: slug, label }` pairs for a registry taxonomy. DataViews filter chips call this on demand. Format and Research Team are primary chips (small lists). Other taxonomies stay under Add filter.
- `POST /prc-api/v3/wp-admin-dataview/field` with `{ postId, field, value, postType }`
- `POST /prc-api/v3/wp-admin-dataview/duplicate` with `{ postId, postType }` → `{ id, edit_url }`
- Saved filters (per user, per post type):
    - `GET /prc-api/v3/wp-admin-dataview/saved-filters?post_type=`
    - `POST /prc-api/v3/wp-admin-dataview/saved-filters` with `{ post_type, name, filters }`
    - `PUT /prc-api/v3/wp-admin-dataview/saved-filters/{id}?post_type=` with `{ name? , filters? }`
    - `DELETE /prc-api/v3/wp-admin-dataview/saved-filters/{id}?post_type=`

### Saved filters popover

Each list screen has a filter icon to the left of the page title. It opens a popover of personal named filter presets for that post type only (overlay, so the DataViews table width does not shift). Editors can save the current DataViews filters, apply a preset (replaces filters), update the active preset, rename, or delete. Sets are stored in user meta (`prc_wp_admin_dataview_saved_filters`) and bootstrapped via `window.prcWpAdminDataview.savedFilters`.

### Status badges

The shell Status field uses `StatusBadge` (`src/fields.jsx`) with tones from `getStatusBadgeTone()` (`src/utils/status-badge.js`). Badges are stop-lights via `@prc/components` `StatusDotBadge`: Gutenberg PostStatus icons (`@wordpress/icons`) tinted to match the label, or an 8px colored dot when there is no Gutenberg icon (`trash`):

| Status    | Tone class  | Icon         | Color                     |
| --------- | ----------- | ------------ | ------------------------- |
| `publish` | `--publish` | `published`  | Success green (`#1a8a1a`) |
| `draft`   | `--draft`   | `drafts`     | Neutral gray (`#757575`)  |
| `future`  | `--future`  | `scheduled`  | Warning amber (`#c07800`) |
| `private` | `--private` | `notAllowed` | Charcoal (`#1d2327`)      |
| `pending` | `--pending` | `pending`    | Warning amber (`#c07800`) |
| `trash`   | `--trash`   | Dot          | Error red (`#cc1818`)     |

Unknown statuses fall back to the draft tone. Bulk edit still writes raw `status` strings through `POST .../field`; only list rendering uses stop-light badges.

### Appearance persistence

DataViews appearance settings persist in browser `localStorage` per post type (`prcWpAdminDataview.appearance.${postType}`). The sparse document stores only settings that differ from current provider defaults, including last-used filters and the last table, grid, or list layout. Search and pagination stay URL or session state. Explicit `dvf_*` / `status=` URL params still win for that visit and are not written to the document unless the user then changes filters.

When a browser has no stored document, the list seeds once from the existing user-meta appearance (localized as `boot.appearance`) and then writes that seed to `localStorage`. After the first write, this browser stops reading stale user meta.

Narrow viewports default to list layout (WordPress `medium`, `max-width: 782px`) until the editor picks a layout. Stored mode still wins. Named saved-filter presets remain user meta.

## Register another post type

Domain plugins own list registration for their CPTs. Hook `prc_wp_admin_dataview_register_lists` (fired on `init` at priority 20):

```php
add_action(
	'prc_wp_admin_dataview_register_lists',
	function ( $lists ) {
		$lists->register(
			array(
				'postType'  => 'decoded',
				'pageSlug'  => 'prc-wp-admin-dataview-decoded',
				'menuTitle' => 'All Decoded',
				'pageTitle' => 'All Decoded',
				'restPath'  => '/my-plugin/v1/library',
			)
		);
	}
);
```

`Bootstrap` registers only core `post` and `page`. Post-like types register from `prc-post-like-types`; page-like types from `prc-page-like-types`. Successful `register()` also sets `post_type_supports( $post_type, 'prc-wp-admin-dataview' )`.

List configs also accept:

- `hideDefaultNewButton`: Hide the shell Add New button when a domain provides its own header actions.
- `menuParent`: Register and highlight the list under another admin menu, such as `edit.php?post_type=campaign`.
- `newUrl`: Override the default `post-new.php` URL. Set it to an empty string to remove the default button.
- `restPath`: Use a domain-owned REST list endpoint.
- `duplicate`: Per-type New Draft copy. Default is enabled for a registered list. Types that never registered a list cannot duplicate. Keys:
    - `enabled` (bool, default `true`)
    - `titleSuffix` (string, default `(Copy)`)
    - `includeMeta` (string[], exact keys or trailing `*`). Empty list copies no meta. Use `*` to copy every key that is not excluded.
    - `excludeMeta` (string[], extra deny list; exact keys or trailing `*`). Platform never-copy keys always re-apply after the filter.
    - `excludeTaxonomies` (string[])
    - `callback` (`callable|null`, `(int $new_id, WP_Post $source): void`). PHP only. Never sent to JS.

After copy the shell fires `prc_wp_admin_dataview_duplicated`. Use `prc_wp_admin_dataview_duplicate_args` to add include keys or extra excludes. The filter cannot drop platform never-copy keys. Report chapters clear `post_parent` so the copy does not stay on the live package. DataViews Duplicate (row or bulk) asks for confirm first, stays on the list, shows a snackbar, and refreshes. Tools → Duplicate on the editor asks for confirm, then creates a draft and opens it.

The full config is available in `window.prcWpAdminDataview.config`. The localized `duplicate` object omits `callback`.

Presence is automatic for registered post types that support the Presence API.
The shell polls room data for title avatars. The "Active editors" filter maps
to the shared REST query and uses active room IDs, so it filters the full list
rather than only the loaded page.

## Host alignment

Owned plugins pin `@wordpress/dataviews` to the host Gutenberg components major. Today that is `18.1.0` against Gutenberg `23.9.0` (`@wordpress/components` 40). Run `npm run check:dataviews-host` before bumping either side. List and settings screens declare a `wp-theme` style dependency so `--wpds-*` tokens load with DataViews CSS. Webpack bundles `@wordpress/kebab-case` because that package does not register a `wp-kebab-case` classic script on Gutenberg 23.9 or WordPress 7.1.

## Build

```bash
npx turbo build --filter=@prc/wp-admin-dataview
npm run check:dataviews-host
npm run check:dataviews-css
```

## ACP audit

```bash
wp eval-file bin/audit-acp-layouts.php -- --post-types=post,page
```
