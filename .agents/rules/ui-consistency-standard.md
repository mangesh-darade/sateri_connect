# UI Consistency Standard (MANDATORY for every screen)

Every screen must look and behave like every other screen. Build from the shared pieces below; never restyle per page. This extends `.cursor/rules/ui-page-header-standard.mdc` (header layout). Run `php tests/UiConsistencyTest.php` after any view or JS change; it must pass.

## 1. Page skeleton
- `<?= $this->extend('layouts/main') ?>`, then `header_actions` and `content` sections. Title, breadcrumb and provider chip come from the layout (`$pageTitle`, `$breadcrumb`, `$subtitle`). **Never** write your own `<h1>` or title row in content.
- Wrap all content in one `<div class="page-list">`. Full-screen tools (inbox, flow builder) set `fullBleed` and are the only exception.
- Section order: filters (one `.card` + `.filter-bar`), hints (`.page-hint`), main `.card`, named groups (`.page-section`).
- Card title: `<div class="card-header"><h2 class="card-title mb-0">Title</h2></div>`.
- Page JS goes in the `scripts` section or `public/assets/js/`; no business logic inline.

## 2. Buttons (colour and alignment)
| Use | Class | Rule |
|---|---|---|
| Primary action (Create, Save, Send, Launch) | `btn btn-wa` | one per area; `btn-sm` in `header_actions` and table rows |
| Secondary / Cancel / Back | `btn btn-outline-secondary` | |
| Destructive | `btn btn-outline-danger` | always confirm with `APP.confirm` / `APP.deleteConfirm` |
| Row actions | icon-only `btn btn-sm btn-outline-secondary` with `title` | right-aligned, `text-end text-nowrap` |

- **Never** use `btn-primary`, `btn-success`, `btn-info`, `btn-dark` or `btn-outline-primary` for actions. Brand colour is `btn-wa` only. Active toggle/tab button = `btn-wa`, inactive = `btn-outline-secondary`.
- Page actions (New, Add, Sync, Export, Refresh, Settings) live **only** in `header_actions`, right aligned, order: secondary first, primary (`btn-wa`) last.
- Form footer: Save (`btn-wa`) on the left, Cancel (`btn-outline-secondary`) next to it. Modal footer: Cancel then Save, right aligned.

## 3. Feedback and dialogs (one way only)
- Success / error messages: `APP.toast(message, 'success'|'error'|'warning'|'info')`.
- Confirmations: `APP.confirm({...}).then(r => { if (!r.isConfirmed) return; ... })` or `APP.deleteConfirm(url)`.
- **Never** call native `alert()`, `confirm()` or `prompt()`.
- Modals: Bootstrap markup + `APP.showModal` / `APP.hideModal`.
- AJAX: `$.ajax` / `APP.post` with `beforeSend` disabling the button and `complete` re-enabling it.

## 4. Shared parts (reuse, do not copy)
- Status chips: `partials/status_badge`. Empty tables/lists: `partials/empty_state`. Alerts: `partials/alerts`.
- Money, dates and numbers use the existing helpers; format money with 2 decimals and the currency code.
- A UI snippet needed in 2+ screens becomes a partial in `app/Views/partials/`; JS needed in 2+ screens becomes an `APP.*` helper in `public/assets/js/app.js`.

## 5. Text and forms
- All UI text is English. Labels above inputs, placeholders as examples only. Errors in `.invalid-feedback`.
- Required fields: `required` attribute plus server validation. Buttons say what they do ("Save campaign", not "Submit").
