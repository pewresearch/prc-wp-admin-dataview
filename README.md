# PRC WP Admin Dataview

Shared DataViews admin list shells for core and shared post types, plus a field-provider contract for domain plugins.

## What it does

- Replaces the classic `edit.php` list for `post` and `page` with a DataViews screen by default.
- Mounts that screen through Gutenberg's `@wordpress/boot` `initSinglePage` runtime when the Boot script module is available (wp-admin chrome stays; Boot owns the stage layout, router `?p=`, and snackbars). Do not npm-install or webpack-bundle `@wordpress/boot`; consume Gutenberg's registered script module only. If Boot is missing, the classic bundle still `createRoot`s `#prc-wp-admin-dataview`.
- Keeps `?classic=1` so bulk actions, screen options, Empty Trash, and Quick Edit stay reachable. On DataViews list screens, open the WordPress command palette (Ctrl/Cmd+K) and run **Switch to classic {type} table** (for example **Switch to classic post table**).
- Shows active editor avatars and can filter to posts that are currently being edited when the Presence API supports the post type.
- Ships a Parent Post column + parent/child filter on the posts list.
- Lets other plugins enrich rows, query args, localized filter metadata, fields, actions, and inline edits (SEO, attachments report, social packages).

## Provider contract

### PHP filters

| Filter                               | Signature                                                                                      | Purpose                                 |
| ------------------------------------ | ---------------------------------------------------------------------------------------------- | --------------------------------------- |
| `prc_wp_admin_dataview_shape_row`    | `(array $row, WP_Post $post, string $post_type): array`                                        | Add fields to each list row             |
| `prc_wp_admin_dataview_query_args`   | `(array $args, WP_REST_Request $request, string $post_type): array`                            | Map DataViews filters to `WP_Query`     |
| `prc_wp_admin_dataview_localize`     | `(array $data, string $post_type): array`                                                      | Pass filter elements / flags to JS      |
| `prc_wp_admin_dataview_update_field` | `(true\|WP_Error\|null $result, int $post_id, string $field, mixed $value, string $post_type)` | Handle inline edit; first non-null wins |

Constants live on `PRC\Platform\Wp_Admin_Dataview\Provider_Registry`.

### JS hooks

| Hook                                      | Signature                                               | Purpose                                                  |
| ----------------------------------------- | ------------------------------------------------------- | -------------------------------------------------------- |
| `prcWpAdminDataview.fields`               | `(fields, { postType, config }) => fields`              | Add / edit DataViews field defs                          |
| `prcWpAdminDataview.actions`              | `(actions, { postType, config, onRefresh }) => actions` | Add or remap row actions. Do not replace the array.      |
| `prcWpAdminDataview.defaultVisibleFields` | `(fields, boot) => fields`                              | Set the initial visible field IDs                        |
| `prcWpAdminDataview.pageDescription`      | `(description, boot) => ReactNode`                      | Add content below the page title                         |
| `prcWpAdminDataview.restQuery`            | `(args, { view, postType, restPath }) => args`          | Map domain field IDs and sorting to REST query arguments |

`prcWpAdminDataview.actions` **adds or remaps** incoming shell actions (quiz remaps labels; chart inserts Duplicate after Edit; email drops View on transactional lists). **Do not replace** the array. Replacing discards shell Edit (real link), View, Trash (bulk + notices), and later shared actions.

Row navigation uses DataViews `renderItemLink` with `item.edit_url`. Prefer that over `onClickItem` on list screens. Keep `onClickItem` only for pickers and modals that select a value instead of navigating.

Editable cell controls must still call `event.stopPropagation()` so the row link does not fire.

### JS SlotFills

The shell provides two named slots. Boot's `RootSinglePage` wraps `SlotFillProvider` (the classic fallback does not add a second provider):

- `prcWpAdminDataview.HeaderActions` renders next to the default Add New button.
- `prcWpAdminDataview.PageExtras` renders after the list body and is intended for domain modals.

The shell entry exports `HeaderActionsFill`, `PageExtrasFill`, `emitPageExtra`, and `subscribePageExtra`. A domain bundle can also call `createSlotFill()` with the exact names above. Provider scripts must depend on the `prc-wp-admin-dataview` script handle.

### REST

- `GET /prc-api/v3/wp-admin-dataview/list`
- `POST /prc-api/v3/wp-admin-dataview/field` with `{ postId, field, value, postType }`
- Saved filters (per user, per post type):
  - `GET /prc-api/v3/wp-admin-dataview/saved-filters?post_type=`
  - `POST /prc-api/v3/wp-admin-dataview/saved-filters` with `{ post_type, name, filters }`
  - `PUT /prc-api/v3/wp-admin-dataview/saved-filters/{id}?post_type=` with `{ name? , filters? }`
  - `DELETE /prc-api/v3/wp-admin-dataview/saved-filters/{id}?post_type=`

### Saved filters popover

Each list screen has a filter icon to the left of the page title. It opens a popover of personal named filter presets for that post type only (overlay, so the DataViews table width does not shift). Editors can save the current DataViews filters, apply a preset (replaces filters), update the active preset, rename, or delete. Sets are stored in user meta (`prc_wp_admin_dataview_saved_filters`) and bootstrapped via `window.prcWpAdminDataview.savedFilters`.

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

The full config is available in `window.prcWpAdminDataview.config`.

Presence is automatic for registered post types that support the Presence API.
The shell polls room data for title avatars. The "Active editors" filter maps
to the shared REST query and uses active room IDs, so it filters the full list
rather than only the loaded page.

## Host alignment

Owned plugins pin `@wordpress/dataviews` to the host Gutenberg components major. Today that is `17.2.0` against Gutenberg `23.6.0` (`@wordpress/components` 37). Run `npm run check:dataviews-host` before bumping either side. List and settings screens declare a `wp-theme` style dependency so `--wpds-*` tokens load with DataViews CSS.

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
