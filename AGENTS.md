# eelKit Agent Instructions

## Upstream framework boundary

eelKit is an upstream framework dependency for downstream projects.

When working in a downstream project, do not modify eelKit source files unless the user explicitly asks to patch eelKit itself. Treat eelKit changes as upstream framework work, not downstream application work.

When working in the eelKit repository itself, source changes are allowed only when the requested task is eelKit framework work.

## Git remotes

Do not push to the `eelKit.git` remote repository unless the user explicitly asks for that exact upstream eelKit push.

For all other remote repositories, follow the downstream project's own instructions and the user's request.

## eelKit-owned paths

In downstream projects, treat these paths as upstream eelKit framework code unless the project documents an intentional override:

- `web_root/classes/`
- `web_root/css/auth.css`
- `web_root/css/index.css`
- `web_root/index.php`
- `web_root/js/index.js`
- `web_root/tests/` except downstream-owned project tests
- `tools/php/`
- `db_schema/`
- `secure/`
- `FreeBSD/`

## Downstream project classes

Downstream projects that add their own PHP classes should put them in the downstream project's own namespace and matching directory under `web_root/classes/{project_name}/`.

For example, a downstream service class should use a namespaced class such as `{ProjectName}\Service\SomeClassService` and a path such as `web_root/classes/{project_name}/service/SomeClassService.php`.

Use lowercase directory names for namespace segments, and keep the PHP class basename filename exact.

Treat project namespace folders under `web_root/classes/{project_name}/` as downstream application code. Treat global, unnamespaced classes under `web_root/classes/` as eelKit framework code unless the project clearly documents otherwise.

Older project-prefixed global service classes, such as `web_root/classes/service/{ProjectName}SomeClassService.php`, are legacy-compatible but should not be the preferred pattern for new downstream code.

If a downstream project needs behavior that appears to require changing eelKit, write a proposal or specification for eelKit instead of editing framework code. Include:

- the downstream need
- the current limitation
- the proposed API or framework change
- compatibility concerns
- any temporary downstream workaround

## Downstream project tests

Downstream projects can add and alter their own tests under `web_root/tests/`.

Treat tests that exercise downstream project behavior as downstream application code, even when they live under `web_root/tests/`. Do not modify eelKit framework tests in that directory unless the user explicitly asks to patch eelKit itself.

## Page, card, action, and context architecture

Prefer eelKit's page -> card -> action pipeline for user-facing application behavior where sensible.

- Pages live in `web_root/content/pages/` and define the route-level shell: page identity, title/subtitle, page-level actions, base context, and the ordered card set or card layout.
- Cards live in `web_root/content/cards/` and define reusable UI panels: card-specific context handling, service-backed data needs, table definitions, helper text, rendering, refresh intervals, and invalidation facts.
- Shared card actions live in `web_root/content/actions/` and handle submitted `card_action` requests. A submitted value such as `SmsSettings` resolves to `SmsSettingsAction`, which must implement `ActionInterfaceFramework`.

Use page actions for page-scoped intents submitted with `action`. Use shared card actions for reusable card behavior submitted with `card_action`. Keep rendering and display decisions in cards, and keep mutation/command handling in actions unless a page-specific action is the clearer fit.

When adding a new feature, first consider whether it can be expressed as:

- a page that selects and arranges cards,
- one or more cards that render data and declare their service needs,
- action classes that perform mutations and return changed invalidation facts,
- context values that carry request, action, page, and card state through the render.

### Card `services()` handlers

A card's `services()` method declares the service calls needed to render that card. Each definition should provide a stable `key`, a service class, a method, and optional params. During rendering, `CardRendererFramework` resolves those definitions through `PageServiceFramework`, invokes the service method, and exposes the result at `$context['services'][$key]`.

Use card `services()` when a card needs read-model data, status rows, counts, table records, or other render-time data that should not be manually fetched inside `render()`. This keeps cards declarative, makes developer metadata more useful, centralizes service error handling, and lets the renderer cache duplicate service calls with the same parameters during a request.

Service params can reference context values by prefixing a string with `:`. Dot notation resolves nested context values, for example `':auth.user_id'` or `':page.page_id'`. If a required context value is missing and the service method parameter has no default, the renderer records a service error for the card instead of silently inventing data.

### Context flow

Context is the shared request-scoped data array passed through pages, cards, services, actions, and renderers. Prefer adding explicit, well-named context values over reaching back into globals or duplicating request parsing in multiple cards.

The normal context flow is:

- the page builds base context, including page identity and requested cards,
- an `ActionResultFramework` can merge extra context from a page action or card action,
- site context is injected by the site context coordinator,
- auth context is added at `$context['auth']`; every page context includes `user_id` and `role_id` there,
- allowed card keys and card DOM IDs are added under `$context['page']`,
- each allowed card's `handle()` method can refine the shared context before rendering,
- the card renderer adds per-card `services` and `service_errors` entries for the card currently being rendered.

Use a card's `handle()` method for card-local request state such as pagination, sorting, selected rows, filters, or preparing context that later cards can consume. Always return the full updated context array. Start from `parent::handle()` when the card should retain the framework's default pagination behavior.

Use `ActionResultFramework::success()` to report changed invalidation facts, flash messages, redirect/query state, and action-produced context. Match card `invalidationFacts()` to the facts returned by actions so AJAX updates refresh the smallest sensible set of cards.

Keep context keys predictable and grouped. Framework-wide page data belongs under `page`, authentication data under `auth`, service results under `services`, service errors under `service_errors`, and feature-specific shared data under a clear feature key. Use `$context['auth']['user_id']` and `$context['auth']['role_id']` for the current user and role identifiers; they are available in every page context.

## Styling

When working in downstream projects, reuse existing eelKit styling from `web_root/css/index.css` before creating project-specific add-on CSS. Prefer existing CSS variables, layout patterns, utility classes, and component styles.

Downstream projects can add `web_root/css/project.css` for project-specific styles. eelKit's `web_root/index.php` checks for that file and adds it to HTML responses after the eelKit CSS when present.

Downstream projects can add `web_root/js/project.js` for project-specific browser JavaScript. eelKit's `web_root/index.php` checks for that file and adds it to HTML responses after the eelKit JavaScript when present.

If a downstream project needs styling that should be reusable across eelKit applications, propose the change for eelKit instead of duplicating or overriding framework styles downstream.
