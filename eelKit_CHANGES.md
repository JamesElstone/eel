# eelKit Changes

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
