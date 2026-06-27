# eelKit Agent Instructions

## Upstream framework boundary

eelKit is an upstream framework dependency for downstream projects.

When working in a downstream project, do not modify eelKit source files unless the user explicitly asks to patch eelKit itself. Treat eelKit changes as upstream framework work, not downstream application work.

When working in the eelKit repository itself, source changes are allowed only when the requested task is eelKit framework work.

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

## Styling

When working in downstream projects, reuse existing eelKit styling from `web_root/css/index.css` before creating project-specific add-on CSS. Prefer existing CSS variables, layout patterns, utility classes, and component styles.

Downstream projects can add `web_root/css/project.css` for project-specific styles. eelKit's `web_root/index.php` checks for that file and adds it to HTML responses after the eelKit CSS when present.

Downstream projects can add `web_root/js/project.js` for project-specific browser JavaScript. eelKit's `web_root/index.php` checks for that file and adds it to HTML responses after the eelKit JavaScript when present.

If a downstream project needs styling that should be reusable across eelKit applications, propose the change for eelKit instead of duplicating or overriding framework styles downstream.
