# eelKit Changes

## Warning flash messages

Feature name: `warning_flash_messages`.

eelKit now supports first-class warning flash messages for successful actions that need to report advisory or partial-success outcomes:

```php
return new ActionResultFramework(true, $changedFacts, [[
    'type' => 'warning',
    'message' => 'Account saved, but transactions have been posted so the transfer marker was not changed.',
]], $query, $context);
```

Warnings render as `.alert.warning`, are styled separately from both success and error messages, and do not mark the action result as failed. Existing string flashes still render and log as success. Existing `type => error` flashes are unchanged, and unknown flash types continue to fall back to success for compatibility.

Flash activity history now preserves `warning` in `application_activity_flash_history.message_type`. Existing installs should run the eelKit migration tool so the activity table enum allows `success`, `warning`, and `error`.

## Generic framework review updates

Feature name: `accepted_upstream_framework_review_updates`.

eelKit now includes the remaining generic framework behaviours accepted from downstream review, so downstream projects can rely on framework APIs instead of patching eelKit-owned files locally.

Response objects now expose their HTTP status through:

```php
$statusCode = $response->statusCode();
```

`ReverseProxyService` now centralizes trusted forwarded request metadata:

```php
$clientIp = (new ReverseProxyService())->clientIpAddress($request);
$host = (new ReverseProxyService())->forwardedHost($request);
$scheme = (new ReverseProxyService())->forwardedScheme($request);
```

Forwarded host and scheme values are only returned when the immediate remote address is listed in `reverse_proxy.trusted_proxy_ips`. eelKit accepts `X-Forwarded-Host`, `X-Forwarded-Proto`, and standard `Forwarded` `host=` / `proto=` values, but rejects invalid hosts, unsupported schemes, and all forwarded values from untrusted remotes. Invitation base URL generation and automatic secure-cookie detection now use this trusted proxy service.

`ActivityStore` now supports API/action flows that do not produce an `ActionResultFramework`:

```php
(new ActivityStore())->recordApiActivity(
    pageId: 'api_uploads',
    actionName: 'UploadFile',
    messageType: 'success',
    messageText: 'Upload accepted',
    userId: $userId,
    metadata: [
        'device_id' => $deviceId,
        'ip_address' => $clientIp,
        'user_agent' => $userAgent,
    ],
    requestMethod: $request->method(),
    requestUri: (string)$request->server('REQUEST_URI', '')
);
```

Flash-message activity metadata also uses `ReverseProxyService::clientIpAddress()`, so recorded IP addresses honor the same trusted proxy configuration as other request handling.

Generated `security.keys` files are now kept private where the platform supports POSIX file modes. eelKit applies `0600` when creating or updating security facts through `SecurityStore::ensureFact()`. Windows skips chmod, and API credential files are unchanged.

Pagination helpers now handle richer framework metadata. `HelperFramework::paginationItemsLabel()` accepts `total_items`, `first_item`, and `last_item`, clamps displayed ranges, and renders single-item ranges as `Items 3 of 10` instead of `Items 3-3 of 10`. `CardBaseFramework::paginationControls()` can render First/Prev/Next/Last controls and accepts optional trusted middle HTML plus an additional wrapper class while preserving existing call signatures.

Request handling keeps the existing framework precedence guarantees: form POST values win over JSON body values in merged post data, and explicit normalized headers win over CGI/FastCGI Authorization fallbacks.

## Project asset hooks

Feature name: `project_asset_hooks`.

eelKit now checks for `web_root/css/project.css` while sending HTML responses through `web_root/index.php`. When the file exists, eelKit adds this browser stylesheet link to the document head:

```html
<link rel="stylesheet" href="css/project.css">
```

eelKit also checks for `web_root/js/project.js`. When the file exists, eelKit adds this browser script tag before the document body closes:

```html
<script src="js/project.js"></script>
```

This gives downstream projects lightweight CSS and JavaScript extension points without editing eelKit-owned `web_root/css/auth.css`, `web_root/css/index.css`, `web_root/js/index.js`, or page renderers. If either project asset is absent, generated HTML for that asset is unchanged.

## Browser helper contract documentation and cleanup

Feature name: `browser_helper_contracts`.

eelKit now documents the generic browser helpers that downstream cards can opt into by emitting `data-*` attributes. These helpers live in `web_root/js/index.js`, use delegated or card-replacement-safe binding, and are intended for downstream markup even when eelKit's built-in cards do not emit every contract.

Dead or broken helpers were removed:

- `triggerStateSync()` was unused and has been removed.
- `data-submit-action` / `#settings_action_field` was a project-specific settings helper and has been removed.

### Changed-state button helper

Use `data-state-fields` and `data-state-target` when a form needs to enable a save button only after one or more fields differ from their original values:

```html
<div data-state-fields="profile-name,profile-type" data-state-target="profile-save"></div>
<input id="profile-name" name="profile_name" value="Original" data-state-default="Original">
<select id="profile-type" name="profile_type" data-state-default="string">...</select>
<button id="profile-save" type="submit" disabled>Save</button>
```

`data-state-fields` is a comma-separated list of element ids. The target must be a button. If `data-state-default` is omitted, eelKit uses the field's current value at binding time as the unchanged value. Checkbox values are compared as `1` for checked and `0` for unchecked.

### Dirty-action button helper

Use `data-dirty-action-target` on a field when one or more buttons in the same form should remain disabled until the field changes:

```html
<input
    name="category_id"
    value="42"
    data-initial-value="42"
    data-dirty-action-target="[name='apply_category']">
<button name="apply_category" value="1" type="submit" disabled>Apply</button>
```

The target value is a CSS selector resolved inside the field's owning form, or inside the element referenced by the field's `form` attribute. Set `data-dirty-require-value="1"` when a non-empty value is also required. Set `data-dirty-enable-mode="selected"` on the field or target button when the button should enable whenever the field has a required value, even if the value is unchanged.

### Page-card tab switch helper

Use `data-page-card-switch-tab` when a button or link should activate an existing page-card tab by its visible label:

```html
<button type="button" data-page-card-switch-tab="Uploads">Show uploads</button>
```

The helper looks in the closest `.page-card-tabs` container, then falls back to the first page-card tab set on the page. Labels are matched case-insensitively after trimming whitespace.

### Submit-field helper

Use `data-submit-field` and optional `data-submit-value` when a submit button needs to set a hidden field immediately before eelKit builds AJAX `FormData`:

```html
<button type="submit" data-submit-field="action" data-submit-value="cancel">Cancel</button>
<input type="hidden" name="action" value="save">
```

If `data-submit-value` is omitted, eelKit writes `1`. The target may be an `input`, `select`, or `textarea` in the submitting button's form.

### Danger-zone confirmation helper

Use danger-zone confirmation controls when a destructive AJAX form should keep its submit button disabled until the user types an expected value.

Clear-confirm controls use:

```html
<input data-clear-confirm-input data-expected-value="CLEAR">
<button type="submit" data-clear-confirm-button disabled>Clear data</button>
```

For backwards compatibility, eelKit also accepts the legacy clear button id `clear-imported-data-button`.

Delete-confirm controls can require both a checkbox and typed confirmation:

```html
<input type="checkbox" data-delete-confirm-checkbox>
<input data-delete-confirm-input data-expected-value="DELETE" disabled>
<button type="submit" data-delete-confirm-button disabled>Delete</button>
```

When the checkbox is unchecked, eelKit disables and clears the delete confirmation input. Buttons are enabled only when the expected text exactly matches the trimmed input value.

### Chicken-check submit confirmation

Use `data-chicken-check="true"` on an AJAX submit button when the user should confirm by pressing a generated confirmation button before the original form submits:

```html
<button
    type="submit"
    class="button danger"
    data-chicken-check="true"
    data-chicken-title="Confirm delete"
    data-chicken-message="This cannot be undone."
    data-chicken-confirm-text="Delete">
    Delete
</button>
```

Optional attributes are `data-chicken-title`, `data-chicken-message`, `data-chicken-confirm-text`, and `data-chicken-button-class`. Messages are rendered as plain text; `<br>` and `<br />` in `data-chicken-message` are converted to line breaks for compatibility.

## Auth context for cards

Feature name: `auth_context_for_cards`.

eelKit now adds authenticated user metadata to the standard page/card context before card `handle()` and `render()` run:

```php
$context['auth'] = [
    'user_id' => 123,
    'role_id' => 2,
];
```

Unauthenticated requests receive `0` for both values. Card service params can reference the values with dotted context paths:

```php
'params' => [
    'userId' => ':auth.user_id',
    'roleId' => ':auth.role_id',
],
```

This context is metadata only. It does not replace card access checks or downstream authorization logic.

## Typed field validation controls

Feature name: `typed_field_validation_controls`.

Downstream projects can now declare simple typed form validation rules directly in markup. eelKit's browser script binds at document level, so validation continues to work after AJAX card replacement and does not need per-card rebinding.

Canonical validation attributes:

```html
<select data-validate-boolean></select>
<input type="text" data-validate-int>
<input type="text" data-validate-float>
<input type="text" data-validate-ascii>
```

`data-validate-int` allows ASCII digits only. `data-validate-float` allows ASCII digits and one decimal point at the browser character-filtering layer. Server validation is stricter: float values must contain at least one digit after any decimal point, so `.5` is accepted but `1.` and `.` are rejected. `data-validate-ascii` strips non-ASCII input. `data-validate-boolean` ensures a select value is either `true` or `false`.

For tables where a row's type control determines a separate value control, pair them with a shared token:

```html
<select name="internal_profile_value_type" data-validate-type-control="profile-12">
    <option value="bool">bool</option>
    <option value="int">int</option>
    <option value="float">float</option>
    <option value="string">string</option>
    <option value="null">null</option>
</select>

<input
    class="input"
    name="internal_profile_value"
    type="text"
    data-validate-type-target="profile-12">
```

The paired value control is sanitized when the type changes. A `null` type clears and disables the paired value control in the browser. Use unique pairing tokens per row; do not rely on duplicate `id="value"` / `id="type"` attributes in repeated table rows.

The reusable PHP helper is `FieldValidationFramework`:

```php
$result = FieldValidationFramework::validateTypedValue($submittedValue, $row['value_type']);

if (empty($result['success'])) {
    return new ActionResultFramework(false, ['internal.profiles'], [[
        'type' => 'error',
        'message' => $result['error'],
    ]]);
}

$valueToStore = $result['value'];
```

Supported type aliases are `bool` / `boolean`, `int` / `integer`, `float` / `decimal` / `number`, `string` / `ascii`, and `null`. Boolean values are canonicalized to lowercase `true` or `false`; `null` ignores the submitted value and returns PHP `null`.

`FieldValidationFramework::renderTypedValueControl()` can render the matching control for a row:

```php
echo FieldValidationFramework::renderTypedValueControl(
    'internal_profile_value',
    $row['value'] ?? '',
    $row['value_type'] ?? 'string',
    ['type_token' => 'profile-' . (int)$row['id']]
);
```

For Internal Profiles-style rows, downstream projects should render `bool`/`boolean` as a `true`/`false` select, `int` as text plus `inputmode="numeric"`, `float` as text plus `inputmode="decimal"`, `string` as ASCII text, and `null` as a disabled empty text input plus a hidden submitted field.

Client validation is only a user-experience layer. Downstream save handlers must still call `FieldValidationFramework::validateTypedValue()` before storing values, because requests can bypass JavaScript.

## Function trace logging

Feature name: `function_trace_logging`.

Downstream projects can now add lightweight function-entry trace logging while investigating slow pages. The framework provides a global helper:

```php
logDetails();
```

Call it at the top of selected page, card, or service methods:

```php
public function render(array $context = []): string
{
    logDetails();

    // Existing render logic...
}
```

Tracing is disabled by default. Enable it in `secure/app.php` by setting `trace.log_path` to a directory that already exists:

```php
'trace' => [
    'log_path' => '../file_logs/trace',
],
```

If `trace.log_path` is empty, null, or points to a missing directory, `logDetails()` caches that disabled state for the request and returns quickly. The helper does not create the directory.

If a trace write fails after tracing has been enabled, `logDetails()` disables tracing for the rest of the request so repeated failed writes do not slow the page down.

When enabled, entries are appended to `{configured-directory}/{yyyy-mm-dd}_trace.csv` with one CSV-safe line per call:

```text
"2026-06-22-14:30:12.123 - function: ExampleCard::render"
```

Relative trace paths resolve under `APP_ROOT`, matching eelKit's existing log-path convention. Windows drive paths, UNC paths, and root-absolute paths such as `/var/log/eelkit-trace/` are preserved as absolute paths.

The trace logger implementation is loaded lazily on the first `logDetails()` call, so pages that do not call `logDetails()` only pay for the small global wrapper declared during bootstrap.

## User mobile numbers

Feature name: `user_mobile_numbers`.

User accounts now support an optional mobile number. The Users page includes a mobile number field when creating a user, a Mobile column in the Current Users table, and a mobile number field in Current User Details so signed-in users can update their own number after confirming their current password.

Mobile numbers are stored in international format. The forms split entry into a country-code selector and local number field, defaulting to United Kingdom `+44`; local leading zeroes are stripped before saving, so `07123 456789` with `+44` is stored as `+447123456789`.

Country codes are held in the new `mobile_country_codes` table and are seeded from the ITU-T E.164 assigned country-code list. Existing installs should run the database migration tool to add `users.mobile_number` and populate the country-code table.

## Clickable table heading sorting

Feature name: `table_heading_sorting`.

`TableFramework` now supports server-side sorting from clickable column headings. Sortable headers render as AJAX-enabled POST buttons, and the active heading includes `aria-sort` plus a compact direction marker.

eelKit's built-in table cards have been wired to use this behaviour, but downstream projects can adopt it case by case. Existing downstream tables will not sort just because the framework has the capability; a card must pass sort state into its table and, for paginated tables, paginate the sorted rows.

### Case-by-case adoption

For an unpaginated downstream table, configure the table with request/context sort values before rendering:

```php
return TableFramework::make('transactions_imported', $rows)
    ->sorting($sortKey, $sortDirection, [
        'page' => (string)($context['page']['page_id'] ?? ''),
        '_invalidate_fact' => 'transactions.imported',
        'cards[]' => ['transactions_imported'],
    ])
    ->column('date', 'Date')
    ->column('description', 'Description')
    ->column('amount', 'Amount', exportType: 'number')
    ->render($context);
```

For paginated tables, sort the full dataset before slicing the visible page:

```php
$table = TableFramework::make('transactions_imported', $rows)
    ->sorting($sortKey, $sortDirection, [
        'page' => (string)($context['page']['page_id'] ?? ''),
        '_pagination' => '1',
        '_invalidate_fact' => 'transactions.imported',
        'cards[]' => ['transactions_imported'],
    ])
    ->column('date', 'Date')
    ->column('description', 'Description')
    ->column('amount', 'Amount', exportType: 'number');

$pagination = HelperFramework::paginateArray($table->sortedRows(), $page, 25);

return $table
    ->visibleRows((array)$pagination['items'])
    ->pagination($pagination, 'Transactions', 'transactions_imported_page', [
        'page' => (string)($context['page']['page_id'] ?? ''),
        '_pagination' => '1',
        '_invalidate_fact' => 'transactions.imported',
        'cards[]' => ['transactions_imported'],
    ])
    ->render($context);
```

If a card extends `CardBaseFramework`, the new helper methods can carry the state for you:

```php
$pageContext = $this->applyTableSortContext($request, $pageContext, 'transactions_imported');

$table = $this->configureTableSorting($table, $context, [
    'page' => (string)($context['page']['page_id'] ?? ''),
    '_invalidate_fact' => 'transactions.imported',
    'cards[]' => ['transactions_imported'],
]);
```

Columns are sortable by default when they are exportable. Screen-only columns with `exportable: false` are not sortable by default. Disable sorting explicitly on any data column that should not become clickable:

```php
->column('actions', 'Actions', html: $actionsHtml, exportable: false)
->column('raw_status', 'Raw Status', sort: false)
```

Compatibility notes:

- Downstream projects can enable sorting one card/table at a time.
- Existing tables keep their current row order until their cards call `sorting()` and, when paginated, use `sortedRows()` before pagination.
- CSV and XLSX exports use the sorted full source rows when sorting is configured.
- Invalid or non-sortable sort keys fall back to the original row order.

## Table toolbar actions

Feature name: `table_toolbar_actions`.

`TableFramework` now lets cards provide custom trusted toolbar HTML that renders in the table's built-in toolbar area, immediately before the Condensed View, CSV, and XLSX controls.

Use the new chainable method:

```php
return TableFramework::make('transactions_imported', $rows)
    ->toolbarActions($bulkActionsHtml)
    ->column(...)
    ->render($context);
```

The supplied HTML is caller-rendered and is not escaped, matching the existing trusted HTML callback behaviour used by table columns. Downstream projects should only pass HTML they intentionally trust.

Rendered order is:

```html
<div class="card-toolbar">
    <div class="actions-row">
        <!-- filters, if any -->
    </div>
    <div class="actions-row">
        <!-- custom toolbar actions -->
        <!-- Condensed View / CSV / XLSX, when exports are enabled -->
    </div>
</div>
```

Compatibility notes:

- Existing table rendering is unchanged when `toolbarActions()` is not used.
- Custom toolbar actions render before Condensed View and export buttons.
- Custom toolbar actions still render when `exports(false)` is set.
- When exports are disabled, Condensed View, CSV, and XLSX remain hidden.
- Filters continue rendering in the first actions row.
- The toolbar is still omitted when there are no filters, no custom toolbar actions, and no built-in controls to show.

## DOM text rendering hardening

Feature name: `dom_text_rendering_hardening`.

eelKit now renders several plain-text JavaScript UI messages with `textContent` instead of `innerHTML`. This reduces latent DOM XSS leverage where downstream projects provide confirmation or status text through `data-*` attributes.

Changed in `web_root/js/index.js`:

- Upload selection summaries now use `textContent`.
- Flash history's empty state is built with DOM nodes and `textContent`.
- Chicken-check confirmation messages now use `textContent` instead of `innerHTML`.

For compatibility, `data-chicken-message` values containing `<br>` or `<br />` are converted to newline characters before rendering. `web_root/css/index.css` now applies `white-space: pre-line` to `.chicken-check-message`, so existing downstream messages that used `<br>` for line breaks should continue to display as multi-line text.

Downstream projects should avoid relying on HTML markup inside `data-chicken-message`. Treat it as plain text. If rich confirmation content is needed, implement a dedicated trusted renderer rather than putting HTML in a `data-*` message attribute.

## Indexed section blocks

Feature name: `indexed_section`.

eelKit now includes a generic CSS pattern for visually separating repeated sections inside a single card. It is intended for dense cards where one top-level card contains several domain entities, for example multiple bank accounts inside one reconciliation card.

The styles live in `web_root/css/index.css` and are CSS-only. No eelKit page rendering changes are required to use them: downstream cards can emit the markup directly inside a normal `.card-body`.

### Basic usage

```html
<section class="indexed-section">
    <div class="indexed-section-marker">
        <div class="indexed-section-number">01</div>
        <div class="indexed-section-label">Account</div>
    </div>
    <div class="indexed-section-main">
        <header class="indexed-section-header">
            <div>
                <h3 class="indexed-section-title">Current Account</h3>
                <div class="indexed-section-helper">Example Bank - 00-00-00 / 00000000</div>
            </div>
            <div class="indexed-section-status">
                <span class="badge success">Continuity pass</span>
                <span class="badge success">Running pass</span>
                <span class="badge warning">Ledger warning</span>
            </div>
        </header>
        <div class="indexed-section-body">
            <!-- Summary grids, tables, panel-soft blocks, forms, and actions go here. -->
        </div>
    </div>
</section>
```

Multiple indexed sections can be placed one after another; adjacent sections receive vertical spacing automatically:

```html
<div class="card-body">
    <section class="indexed-section">...</section>
    <section class="indexed-section">...</section>
    <section class="indexed-section">...</section>
</div>
```

### CSS hooks

The semantic classes are:

- `.indexed-section`
- `.indexed-section-marker`
- `.indexed-section-number`
- `.indexed-section-label`
- `.indexed-section-main`
- `.indexed-section-header`
- `.indexed-section-title`
- `.indexed-section-helper`
- `.indexed-section-status`
- `.indexed-section-body`

The layout uses a left index rail on wider viewports. On narrow viewports, the rail becomes a horizontal top band so the block remains readable without cramping the content.

### Rendering guidance

Use indexed sections when the repeated block is subordinate to one card but still needs a strong visual boundary. Do not wrap each repeated item in another `.card`; that makes the hierarchy ambiguous and duplicates the top-level card treatment.

For banking reconciliation-style cards, the account should be the indexed section. Internal headings such as `Ledger Reconciliation` should remain smaller headings inside `.indexed-section-body`, because the indexed header is the main separator.

Do not apply `.card-title-toggle` to nested indexed-section headings unless the downstream app also implements separate nested-section JavaScript. eelKit's built-in `.card-title-toggle` behaviour is for top-level cards and toggles the nearest `.card` body.

If downstream apps want generated markup instead of manual HTML, they can add their own helper or service method later. The framework does not need a page renderer change for the CSS-only pattern.

## Month Heat Map component

Feature name: `month_heatmap`.

eelKit now has a compact, server-rendered month-level heatmap for showing status coverage across arbitrary accounting or reporting periods. It is intended for flows such as statement uploads where users need to spot missing months, balance-continuity gaps, or months that need manual review at a glance.

The component lives in `ChartService` as `monthHeatmap(array $options): string`.

### Basic usage

```php
$html = $chartService->monthHeatmap([
    'id' => 'statement-coverage',
    'label' => 'Statement coverage',
    'start' => '2022-09-05',
    'end' => '2023-09-30',
    'months' => [
        [
            'month_key' => '2022-09-01',
            'label' => 'Sep 2022',
            'status' => 'fail',
            'value' => 0,
            'tooltip' => 'No CSV rows found for September 2022. Upload the missing statement or confirm this month had no transactions.',
        ],
        [
            'month_key' => '2022-10-01',
            'label' => 'Oct 2022',
            'status' => 'pass',
            'value' => 5,
            'secondary_value' => 3,
            'tooltip' => '5 rows uploaded. Opening balance matches previous closing balance.',
        ],
    ],
    'legend' => [
        'pass' => 'Covered',
        'warning' => 'Needs review',
        'fail' => 'Gap',
        'muted' => 'No data',
    ],
]);
```

`start`/`end` and `start_date`/`end_date` are both supported. The renderer normalises those dates to month boundaries and renders every month between them, so periods such as September to September, short quarters, and periods longer than 12 months all work without Jan-Dec assumptions.

If `start` or `end` are omitted, the range is inferred from the first and last valid `month_key` values in `months`. If a month inside the rendered range has no supplied item, it is rendered with `missing_status`, which defaults to `fail`.

### Month item fields

Each `months` item supports:

- `month_key`: Any valid `Y-m-d` date in the month. It is normalised to `Y-m-01`.
- `label`: Visible/a11y month label, for example `Sep 2022`.
- `status`: One of `pass`, `warning`, `fail`, or `muted`. Unknown statuses fall back to `muted`.
- `value`: Non-negative numeric count/value displayed inside the cell. Invalid or negative values become `0`.
- `secondary_value`: Optional non-negative numeric second-line value for `ChartService::monthHeatmap()`. When present, the cell renders the main `value` on the first line and the secondary value below it in parentheses, for example `5<br>(3)`.
- `tooltip` or `title`: Native hover/focus tooltip text. `tooltip` takes precedence.

When a secondary value is present, the visible month cell value is rendered on two lines:

```html
5<br><span class="month-heatmap-cell-secondary-value">(3)</span>
```

The same value is also emitted as `data-month-secondary-value`, so consuming apps can attach later behaviour without reparsing the visible label.

Status meanings used by the example:

- `pass`: Month covered and continuity OK.
- `warning`: Rows exist but continuity cannot be confirmed.
- `fail`: Missing month or balance mismatch.
- `muted`: Outside selected range, unavailable, future, or intentionally no data.

### Markup and accessibility

The component renders plain HTML and no JavaScript. Each month is a keyboard-focusable `<button type="button">` with:

- `title` for native tooltip behaviour
- `aria-label` containing the month, legend label, and tooltip detail
- `data-preserve-title="true"` to match the existing calendar heatmap tooltip pattern
- `data-month-key`, `data-month-status`, and `data-month-value` for downstream behaviour if needed

The root element uses `role="group"` and an accessible label derived from `label` or `title`.

### CSS hooks

The styling is in `web_root/css/index.css` and uses these semantic classes:

- `.month-heatmap`
- `.month-heatmap-scroll`
- `.month-heatmap-cell`
- `.month-heatmap-cell--pass`
- `.month-heatmap-cell--warning`
- `.month-heatmap-cell--fail`
- `.month-heatmap-cell--muted`
- `.month-heatmap-legend`
- `.month-heatmap-legend-swatch`

The default layout is a compact single horizontal row. It uses horizontal scrolling when the rendered period is wider than the available card space.

### Demo and tests

`ChartService::demoCalendarCharts()` now returns a `month_heatmap` demo alongside the existing `calendar_heatmap`. The example is rendered directly below the calendar heatmap in `web_root/content/cards/chart_calendar_heatmap.php`.

Test coverage is in `web_root/tests/test_ChartService.php` and checks:

- range rendering over odd accounting-period dates
- status class output
- missing-month fallback output
- `title`, `aria-label`, and `data-preserve-title`
- demo output includes all four statuses
- no inline styles, scripts, or event handler attributes

## Generic site context extension point

Feature name: `site_context`.

eelKit now has a generic framework socket for application/site context selectors and resolved context injection. The framework does not know about any domain-specific concepts such as companies, tax years, periods, finance data, or consuming-app service names. It only understands:

- a configured site context provider service
- resolved context arrays
- structured selector definitions
- framework-owned selector slots
- a generic `set-site-context` action

This is intended for consuming apps that need to resolve a current application context, render one or more selectors in page chrome, and make the selected values available to page/cards and card service params.

### Configure a provider

Add an optional provider service to app config:

```php
'site_context' => [
    'service' => YourAppSiteContextService::class,
],
```

If `site_context.service` is blank or omitted, eelKit uses `NullSiteContextProviderFramework` and behaves as it did before: empty slots are rendered with no selector UI, no context is injected, and no app-specific assumptions are made.

The configured provider is resolved through `AppService`, so normal eelKit service construction and constructor dependency resolution still apply.

### Implement the provider

The provider must implement `SiteContextProviderInterface`:

```php
final class YourAppSiteContextService implements SiteContextProviderInterface
{
    public function resolveSiteContext(
        RequestFramework $request,
        PageInterfaceFramework $page,
        PageServiceFramework $services,
        array $pageContext
    ): SiteContextResultFramework {
        return new SiteContextResultFramework(
            context: [
                'site_context' => [
                    'workspace_id' => 123,
                    'reporting_window_id' => 456,
                ],
                'workspace' => [
                    'id' => 123,
                    'name' => 'Example Workspace',
                ],
            ],
            selectors: [
                [
                    'key' => 'workspace_id',
                    'input_name' => 'workspace_id',
                    'slot' => 'sidebar',
                    'label' => 'Workspace',
                    'value' => '123',
                    'options' => [
                        ['value' => '123', 'label' => 'Example Workspace', 'short_label' => 'Example'],
                    ],
                    'disabled' => false,
                    'visible' => true,
                ],
                [
                    'key' => 'reporting_window_id',
                    'input_name' => 'reporting_window_id',
                    'slot' => 'topbar',
                    'label' => 'Reporting Window',
                    'value' => '456',
                    'options' => [
                        ['value' => '456', 'label' => 'Current Window'],
                    ],
                    'disabled' => false,
                    'visible' => true,
                ],
            ]
        );
    }

    public function handleSiteContextAction(
        RequestFramework $request,
        PageInterfaceFramework $page,
        PageServiceFramework $services
    ): ActionResultFramework {
        $key = (string)$request->input('site_context_key', '');
        $value = (string)$request->input('site_context_value', '');
        if ($value === '') {
            $inputName = (string)$request->input('site_context_input_name', '');
            $value = $inputName !== '' ? (string)$request->input($inputName, '') : '';
        }

        // Validate and persist the selected value in the app-owned way,
        // for example session state or a user preference table.

        return ActionResultFramework::success();
    }
}
```

Provider responsibilities:

- Resolve canonical app context from the request, session, current page, services, or app storage.
- Handle `action=set-site-context` updates using the generic `site_context_key` and `site_context_value` form fields, or the optional rendered `input_name` field when present.
- Return context arrays to merge into the page context.
- Return structured selector definitions for eelKit to render.

The context array is merged before card handling, so card service params can reference injected values:

```php
[
    'key' => 'example_lookup',
    'service' => ExampleLookupService::class,
    'method' => 'fetch',
    'params' => [
        'workspaceId' => ':site_context.workspace_id',
    ],
]
```

eelKit does not interpret the keys under `site_context`; the consuming app owns their meaning.

### Selector slots

Selectors are rendered by `SiteContextRendererFramework` from structured selector data. Supported slots are:

- `sidebar`
- `topbar`
- `summary`

The full layout always includes these framework-owned DOM slots:

```html
<div id="site-context-sidebar-slot"></div>
<div id="site-context-summary-slot"></div>
<div id="site-context-topbar-slot"></div>
```

The sidebar slot is inside the sidebar brand block, after the brand toolbar and before navigation. The topbar and summary slots are in the page chrome above cards, inside `.topbar-right`; they are not inside `.page-stack` or any card.

Selector definitions use this shape:

```php
[
    'key' => 'workspace_id',
    'input_name' => 'workspace_id',
    'slot' => 'sidebar',
    'label' => 'Workspace',
    'value' => '123',
    'options' => [
        [
            'value' => '123',
            'label' => 'Example Workspace',
            'short_label' => 'Example',
        ],
    ],
    'disabled' => false,
    'visible' => true,
]
```

`key` is the canonical generic site-context identity. `input_name` is optional and controls the rendered/submitted HTML field name. If `input_name` is omitted, the visible selector uses the generic `name="site_context_value"` field. If `input_name` is present and valid, the visible selector uses that field name and eelKit also emits hidden metadata:

```html
<input type="hidden" name="site_context_key" value="workspace_id">
<input type="hidden" name="site_context_input_name" value="workspace_id">
<select name="workspace_id" data-site-context-key="workspace_id" data-site-context-input-name="workspace_id">
```

Accepted `input_name` values must match:

```text
^[A-Za-z_][A-Za-z0-9_]*$
```

Invalid names are ignored and eelKit falls back to `name="site_context_value"`.

Selectors render as normal eelKit AJAX forms with:

- `method="post"`
- `data-ajax="true"`
- hidden `action=set-site-context`
- hidden `page`
- hidden `_ajax=1`
- hidden `cards[]` values for the current page cards
- hidden `site_context_key`
- hidden `site_context_input_name`, when a valid selector `input_name` is configured
- select field `site_context_value`, or the configured `input_name`

The renderer reuses existing selector classes: `selector-form`, `selector-shell`, `selector-label`, `selector-input`, and `sidebar-select` for sidebar selectors. No inline JavaScript is required; existing frontend change handling auto-submits AJAX selector forms.

Whenever site-context selector `<select>` elements are rendered in the UI DOM, eelKit also submits their current values with every form submit and every eelKit AJAX request. This includes selectors in the `sidebar`, `topbar`, and `summary` slots, and includes disabled selectors because their current value may still describe the active app context.

For normal form posts and AJAX form posts, `web_root/js/index.js` injects hidden fields into the submitting form before submission:

```html
<input type="hidden" name="site_context_keys[]" value="workspace_id">
<input type="hidden" name="site_context_values[]" value="123">
<input type="hidden" name="workspace_id" value="123">
```

Named hidden fields are added only for selectors with a valid `input_name`, and only when the submitting form does not already contain an enabled field with that name. This keeps app-owned form fields from being duplicated or overwritten.

For non-form AJAX requests, such as card auto-refresh, the JSON payload carries the same data as parallel arrays:

```json
{
  "site_context_keys": ["workspace_id", "reporting_window_id"],
  "site_context_values": ["123", "456"],
  "workspace_id": "123",
  "reporting_window_id": "456"
}
```

The shared frontend `sendAjax()` helper augments JSON AJAX payloads with the same arrays, so new eelKit AJAX callers using that helper inherit the behaviour. Providers should still treat their own session/app storage as canonical, but these submitted arrays let app-specific actions and card services see the visible selector state on any request. The arrays are ordered pairs: `site_context_keys[0]` matches `site_context_values[0]`.

### Page-level selector suppression

Pages can hide named selectors without changing `PageInterfaceFramework` by adding an optional method:

```php
public function hiddenSiteContextSelectors(): array
{
    return ['reporting_window_id'];
}
```

Only selectors whose `key` appears in the returned array are suppressed. Other selectors continue to render in their configured slots.

### AJAX behaviour

When the provider handles `action=set-site-context`, `SiteContextCoordinatorFramework` adds broad invalidation facts:

```php
page.reload
site-context.ui
```

This refreshes relevant cards and the selector UI for whole-app context changes. AJAX delta responses can include:

```json
{
  "site_context_html": {
    "sidebar": "...",
    "topbar": "...",
    "summary": "..."
  }
}
```

`web_root/js/index.js` applies `site_context_html` directly to the slot elements, so a selector change does not require replacing the whole sidebar. Existing `sidebar_html` remains available for navigation/sidebar layout changes.

### Framework classes

The extension point is implemented by:

- `SiteContextProviderInterface`
- `SiteContextResultFramework`
- `NullSiteContextProviderFramework`
- `SiteContextCoordinatorFramework`
- `SiteContextRendererFramework`

`PageServiceFramework::siteContextCoordinator()` exposes the coordinator to framework page handling. `web_root/index.php` initialises it after auth, page access, and `PageServiceFramework` construction. `PageBaseFramework` calls it before normal action dispatch for `set-site-context`, and injects resolved context before card handling.

## Conditional field visibility helper

Feature name: `data-visible-when-field`.

eelKit now includes a generic frontend helper for showing or hiding a field, field group, or any other HTML container based on another form control's current value. This lets cards express simple conditional form behaviour in markup without inline JavaScript or card-specific selectors.

Add the helper attributes to the element that should be conditionally visible:

```html
<div
  data-visible-when-field="charging_model"
  data-visible-when-value="weight">
  <label for="cost_per_kg_pence">Cost per kg, pence</label>
  <input id="cost_per_kg_pence" name="cost_per_kg_pence" type="number">
</div>
```

The value in `data-visible-when-field` should match the controlling field's `name` or `id`. The value in `data-visible-when-value` is compared with the controlling field's current value.

Full example:

```html
<select name="charging_model">
  <option value="row_price">Row price</option>
  <option value="weight">Weight</option>
  <option value="manual">Manual</option>
</select>

<div
  data-visible-when-field="charging_model"
  data-visible-when-value="weight">
  <input name="cost_per_kg_pence" type="number">
</div>
```

The helper runs on initial page load and after AJAX card replacement. It supports `input`, `select`, `textarea`, checkbox, and radio controls as the source field.

When the condition is not met, eelKit sets `hidden` and `aria-hidden="true"` on the target. Nested `input`, `select`, `textarea`, and `button` controls are disabled while hidden so hidden values are not submitted by default. Previously disabled nested controls are restored to their previous disabled state when the target becomes visible again.

To hide the target but leave nested controls enabled, opt out explicitly:

```html
<div
  data-visible-when-field="charging_model"
  data-visible-when-value="manual"
  data-visible-when-disable-controls="false">
  ...
</div>
```

## Flash message activity history

Feature name: `ActivityStore::recordFlashMessages()`.

eelKit now records framework flash messages as application activity. Flash messages emitted through `ActionResultFramework` are captured centrally after action dispatch, so downstream pages, cards, and shared actions do not need to duplicate logging code for user-facing success or error notices.

The new framework table is:

```text
application_activity_flash_history
```

It stores the flash type, plain message text, optional text derived from `message_html`, page id, page action, card action, authenticated user when available, request metadata, and timestamp.

Read access is exposed through:

```php
(new LogsRepository())->fetchRecentFlashActivity(limit: 200);
```

The built-in `activity` card now uses `LogsRepository::fetchRecentFlashActivity()` and is included on the `logs` page. Downstream projects can reuse the same card or call the repository method directly for dashboard, audit, or activity-log views.

Existing user security history remains separate: `UserHistoryStore` continues to own login/account audit records, while flash activity is written through `ActivityStore` and queried through the logs read layer.

Run the eelKit migration tool to create the activity table on existing installs:

```text
php tools/php/setupDb.php --migrate-only
```

## Calendar Heat Map Graph

Feature name: `ChartService::calendarHeatmap()`.

eelKit now includes a server-rendered calendar heat map graph for day-by-day activity counts. The heat map renders as HTML buttons rather than SVG, so downstream cards can post the selected day through the existing eelKit AJAX flow and update related card content such as a `TableFramework` table.

Basic service input:

```php
$html = (new ChartService())->calendarHeatmap([
    ['date' => '2026-05-12', 'value' => 1],
    ['date' => '2026-05-13', 'value' => 2],
    ['date' => '2026-05-14', 'value' => 4],
], [
    'title' => 'Calendar based Heat Map',
    'id' => 'activity-calendar',
    'start_date' => '2026-01-01',
    'end_date' => '2026-12-31',
    'selected_date' => '2026-05-14',
    'input_name' => 'calendar_heatmap_date',
    'year_input_name' => 'calendar_heatmap_year',
    'years' => [2024, 2025, 2026],
    'value_label' => 'records',
]);
```

Each day button includes a submitted date value, accessible label, native hover title, and a level class:

```html
<button
  class="calendar-heatmap-day calendar-heatmap-day-level-4 is-selected"
  type="submit"
  name="calendar_heatmap_date"
  value="2026-05-14"
  title="4 records on 14 May 2026"
  aria-label="4 records on 14 May 2026"
  data-preserve-title="true">
```

Colour is controlled in CSS using `--accent: var(--primary)` and heat level classes, not by passing colours into the service. The heat map output is CSP-friendly: it does not require inline JavaScript or inline styles.

The Example Graphs page now includes a `Calendar based Heat Map` card demonstrating selection via AJAX. The example stores `calendar_heatmap_date` and `calendar_heatmap_year` in card context and displays the selected day.

### Service rename

The chart service has been renamed from `ChartSvgService` to `ChartService` because it now renders both SVG charts and HTML chart components. Downstream cards should update service definitions from:

```php
'service' => ChartSvgService::class,
```

to:

```php
'service' => ChartService::class,
```

## Card-level auto-refresh polling

Feature name: `CardInterfaceFramework::refreshIntervalMs()`.

Cards can now ask eelKit to poll and re-render themselves after each server render. The polling decision is made server-side, so downstream JavaScript does not need to understand card-specific statuses such as queued jobs.

Implement the hook on a card:

```php
public function refreshIntervalMs(array $context): ?int
{
    foreach ($this->rows($context) as $row) {
        if (in_array((string)($row['job_status'] ?? ''), ['queued', 'processing'], true)) {
            return 5000;
        }
    }

    return null;
}
```

`CardBaseFramework` returns `null` by default. When a card returns a positive interval, `CardRendererFramework` emits refresh metadata on the card section:

```html
<section
  class="card"
  data-card-key="intake_queue"
  data-card-refresh-ms="5000"
  data-card-refresh-fact="intake.queue">
```

Intervals are clamped to a minimum of 5000ms. The browser schedules one timer per connected card, skips refreshes while the tab is hidden or the card already has a request in flight, and posts:

```text
_ajax=1
_card_refresh=1
_invalidate_fact=<card refresh fact>
cards[]=<card key>
```

The existing AJAX delta renderer replaces the returned card HTML using `replaceCards()`. If the next render returns `null` from `refreshIntervalMs()`, the new card markup has no refresh attributes and polling stops automatically.

## Normal POST tab retention with `show_card`

Feature name: `show_card` hidden input.

Normal non-AJAX form submits can now request which tab should be active after the page reloads. This is intended for forms that should stay as normal browser submits, especially multipart file uploads that rely on PHP `$_FILES`.

Add a hidden `show_card` field to the form:

```html
<input type="hidden" name="show_card" value="target_card_key">
```

When the page renders after submit, eelKit selects the tab containing `target_card_key`. The tab order is unchanged.

### Show the submitting card with `.self`

Use `.self` when the page should return to the tab containing the card that submitted the form:

```html
<form method="post" action="?page=deliveries" enctype="multipart/form-data" class="form-grid" data-upload-dropzone>
    <input type="hidden" name="show_card" value=".self">
    ...
</form>
```

Before the normal browser submit, eelKit resolves `.self` to the closest card key. This keeps standard file uploads working through PHP `$_FILES` while allowing the page to return to the relevant tab.

If the page has no tabs, `show_card` is harmless and has no visible effect.

### When to use it

Use this when a downstream page has tabs and a normal POST form on a later tab would otherwise return the user to the first tab after submit.

Use an explicit card key when one card should submit and then show a different card's tab:

```html
<input type="hidden" name="show_card" value="delivery_summary">
```

Use `.self` when the form should return to the card that contains the form:

```html
<input type="hidden" name="show_card" value=".self">
```
