# NXT Fluent Boards Extension

A companion plugin for **Fluent Boards / Fluent Boards Pro** and **FluentCRM** that we at
[nexTab](https://nextab.de) run on our internal CRM. Fluent Boards is our day-to-day project
board. Most of this plugin works around bugs or missing extension points in Fluent Boards.

**This README is written for the Fluent Boards team.** Each section describes what we
observed, the root cause in the current code, a suggested fix in core, and how we work
around it. We would much rather delete our workarounds than maintain them.

Verified against **Fluent Boards / Pro 2.2.2** and **FluentCRM 3.2.6** (October 2026).

---

## Summary for the Fluent Boards team

| # | Problem | Kind | Status in 2.2.2 | Our module |
|---|---------|------|-----------------|------------|
| 1 | [List view only ever shows the first 20 tasks per stage](#1-list-view-only-shows-the-first-20-tasks-per-stage) | Bug | **open** | `list_view_all_tasks` |
| 2 | [Moving a task to another board deletes all custom-field values](#2-moving-a-task-to-another-board-deletes-its-custom-field-values) | Missing hook | **open** | `board_move_preserve_custom_fields` |
| 3 | [No extension point for the task detail view](#3-no-extension-point-for-the-task-detail-view) | Missing API | **open** (and broke our buttons) | `automations` |
| 4 | [MCP `create-task` / `update-task` cannot set labels](#4-mcp-create-task--update-task-cannot-set-labels) | Missing feature | **open** | `boards_task_labels_mcp` |
| 5 | [FluentCRM MCP has no company tools](#5-fluentcrm-mcp-has-no-company-tools) | Missing feature | **open** (FluentCRM 3.2.6) | `crm_companies_mcp` |
| – | [Time tracking lost on board move](#fixed-upstream) | Bug | fixed upstream | `board_move_preserve_time` (off) |
| – | [FluentCRM MCP `list-contacts` ignored `page` / `per_page`](#fixed-upstream) | Bug | fixed in FluentCRM 3.2.6 | `crm_list_contacts_pagination_fix` |

---

## 1. List view only shows the first 20 tasks per stage

**What users see.** One of our boards has a stage with 29 open tasks. In the list view it
shows 20 tasks and then simply ends: no "load more", no error. Scrolling does nothing. The
stage header says 29, and opening the 20th task shows "20 / 29" in the task navigation.
Every stage with more than 20 tasks is affected; on our installation that is 5 boards,
with up to 97 tasks in one stage. Users have no way to reach tasks 21+ in this view.

**Root cause.** It takes three things together:

1. `TaskController::getTasksByBoardStage()`
   (`app/Http/Controllers/TaskController.php`, line 104 ff.) loads only `->limit(20)` tasks per
   stage (line 123) and returns `pagination_by_stage` with `has_more_after: true`.
2. The frontend loads further pages only from `ListView.handleScroll(stageId)`. This is bound
   via `@scroll` to the per-stage draggable `.fbs_table_items`, and it checks *that element's*
   `scrollTop`. In the Kanban view every column is its own scroll container, so it works
   there. In the list view `.fbs_table_items` has no height limit and no `overflow`; the element
   that actually scrolls is `.fbs_list_view_scroller` or the page. **The scroll event never fires,
   so `fetchStageTasksPage()` is never called.**
3. Even with more tasks loaded, `ListView` only renders a window of tasks
   (`visibleTasks`: 20 initially, at most 25 via `loadMoreTasks('bottom' | 'top')`). Rows outside
   the window are rendered empty. That window is also only moved from `handleScroll`.

**Suggested fix in core.** Any of these would solve it:

- Treat the list view like the calendar and Gantt views: add `'list-view'` to
  `layoutRequiresFullTaskLoad()`, so the board loads through `/tasks`, and drop the `visibleTasks`
  window in `ListView`. A list view needs every row anyway.
- Or trigger paging from what actually scrolls, for example an `IntersectionObserver` sentinel
  at the end of each stage, and move the window accordingly.
- Or at least show a "Load more" button per stage while `has_more_after` is true.

**Our workaround.** `assets/list-view-all-tasks.js`, loaded via `fluent_boards/after_enqueue_assets`:

- It finds the `ListView` component instance from the Vue app root (`[data-v-app]` → `_vnode`).
- It replaces `isTaskVisible` with `() => true`.
- Whenever a stage is incomplete, it calls the board component's own `loadAllBoardTasks()`, the
  same path the calendar and Gantt views use.

This depends on internal component and method names. If they change, the script does nothing
and logs one warning to the console.

A side note from building it: chaining `fetchStageTasksPage()` in the list view does not work
well. `stageTaskPagination` describes only the *last loaded window*: after a `next` page,
`has_more_before` becomes `true` although everything before is already in the store. A naive
"load until `has_more_*` is false" loop then bounces between pages.

---

## 2. Moving a task to another board deletes its custom-field values

**What users see.** Our workflow moves a card through several boards, and these boards share
custom fields with the same slug. After each move, all custom-field values are gone.

**Root cause.** `TaskService::changeBoardByTask()` (`app/Services/TaskService.php`,
line 1071 ff.) always calls `removeCustomFieldAssociations($task)` → `$task->customFields()->detach()`
(line 1210). There is no filter to keep the values. `fluent_boards/task_moved_from_board` fires
only after the transaction, when the values are already deleted, and there is no "before move"
action. Labels, assignees, watchers and recurring settings are dropped the same way.

**Suggested fix in core.**

- Remap custom-field values to fields with the same slug on the target board (and drop the
  rest), or
- add a filter, for example `fluent_boards/task_move_keep_custom_fields`, or
- fire `fluent_boards/before_task_moved_from_board` with the task and both boards *before* anything
  is detached, so add-ons can take a snapshot.

**Our workaround.** `includes/CustomFieldPreserver.php` + `includes/BoardMovePreserver.php`:

- Keep a shadow copy of a task's values (transient) on every `fluent_boards/task_custom_field_changed`.
- Take a fresh snapshot before REST `move-task` and cross-board `bulk-actions` requests
  (`rest_pre_dispatch` / `rest_request_before_callbacks`).
- On `fluent_boards/task_moved_from_board`, write the values back to fields with the same slug,
  or the same title, on the target board.

---

## 3. No extension point for the task detail view

**What we built.** Admins can configure **automation buttons**:

- Each button runs a sequence of actions on the open task: set labels, priority or status,
  move to another board, add or remove an assignee.
- Button labels reach the app via `fluent_boards/app_vars`; actions run server-side through an
  AJAX endpoint with a permission check through `FluentBoardsApi('tasks')`.
- Configuration lives under *Settings → FBS Automationen*.

**Problem.** There is no official way to add anything to the task detail view, so the buttons
were injected into the DOM container `.fbs-task-sidebar-action-btns`. In 2.2 the sidebar was
redesigned into `.fbs-task-sidebar-card` tiles with the actions in the ⋮ menu. That container
no longer exists, so **our buttons silently disappeared**. Add-ons that append their own
`.fbs-task-sidebar-card` instead will break the same way on the next redesign.

On top of that, the frontend portal (`FrontendRenderer::shouldKeepPortalAsset()`, Pro) dequeues
every script that is not on an allow-list. Add-ons must know about
`fluent_boards/portal_asset_listed_slugs` (line 180), and the filter is not documented.

**Suggested fix in core.**

- A documented filter for task sidebar cards and for ⋮-menu items. It could return items like
  `{ id, title, icon, component | url | ajax_action }`, receive the task and board, and include
  a permission callback.
- A small public JS API, for example `window.FluentBoardsApp.on('task:opened' | 'task:closed' |
  'task:updated', cb)`, exposing the internal event bus.
- A note in the developer docs that portal assets must be allow-listed, and with which filter.

**Our workaround.** `includes/AutomationHandler.php`, `includes/ActionExecutor.php` and
`assets/fbs-automation.js`. They are currently broken in 2.2.2 because the container is
missing; see above.

---

## 4. MCP `create-task` / `update-task` cannot set labels

**Problem.** Fluent Boards 2.2.2 has `fluent-boards/add-task-label` and
`fluent-boards/remove-task-label`. The input schemas of `fluent-boards/create-task` and
`fluent-boards/update-task` (`app/Modules/MCP/AbilitiesRegistrar.php`) have no `labels`
parameter. An AI agent creating a labelled task therefore needs one call per label, and it
cannot replace a task's label set in one step.

**Suggested fix in core.** Accept `labels` (array of label IDs, or slugs and titles) in
`create-task` and `update-task`.

**Our workaround.** `includes/MCP/TaskLabelsMCP.php` re-registers both abilities on
`fluent_boards/mcp_loaded` with an additional `labels` parameter (IDs, titles or slugs). It
calls the core handler and then applies the labels.

---

## 5. FluentCRM MCP has no company tools

**Problem.** FluentCRM 3.2.6 ships an MCP server with contact, tag, list, campaign and
automation tools, but nothing for **Companies**. Agents cannot look up or maintain companies.

**Our workaround.** `includes/MCP/CompaniesMCP.php` registers these abilities on the FluentCRM
MCP server, via `fluent_crm/mcp_loaded`, `fluent_crm/mcp_ability_names`,
`fluent_crm/mcp_safety_levels` and `fluent_crm/mcp_capabilities`:

`fluent-crm/list-companies`, `get-company`, `upsert-company`, `delete-company`,
`attach-contacts-to-company`, `detach-contacts-from-company`, `add-company-note`,
`delete-company-note`

---

## Fixed upstream

We keep these modules as switchable fallbacks.

- **Time tracking on board move.** Older 2.x versions hard-deleted time tracks in
  `changeBoardByTask()`. 2.2 now moves them via `fluent_boards/task_moved_update_time_tracking`
  (Pro). Our `TimeTrackingPreserver` is therefore **off by default**; turning it on would
  duplicate entries.
- **FluentCRM MCP `list-contacts` pagination.** `page` and `per_page` used to be ignored, so every
  call returned page 1 with 15 rows. In FluentCRM 3.2.6, `MCPHelper::paginationFromInput()` now
  also sets the request object, so this looks fixed. We verified that by reading the code; we
  have not yet tested it with our module switched off. Our `ContactListPaginationFix` can most
  likely be switched off.

---

## Site-specific modules (not Fluent Boards issues)

| Module | Why |
|--------|-----|
| `mcp_endpoint_compat` | For servers whose nginx returns 404 for `/wp-json/*`. This module rewrites the URLs of every `<namespace>/mcp` endpoint to `index.php?rest_route=…`. |
| `mcp_ensure` | Re-enables `mcp_enabled` once and re-runs `MCPInit` if the MCP server was not registered, for example because of a race with the Abilities API polyfill. |

---

## Installation and settings

1. Copy the folder `nxt-fbs-extension` to `wp-content/plugins/` and activate it.
2. Requirements:
   - Fluent Boards (Pro for custom fields and time tracking)
   - FluentCRM for the CRM / MCP modules
   - PHP 7.4+
3. *Settings → NXT Fluent Boards* lets you switch each module on or off; disabled modules load
   no code.

| Module ID | Default |
|-----------|---------|
| `list_view_all_tasks` | on |
| `board_move_preserve_custom_fields` | on |
| `board_move_preserve_time` | off |
| `automations` | on |
| `boards_task_labels_mcp` | on |
| `crm_companies_mcp` | off |
| `crm_list_contacts_pagination_fix` | on |
| `mcp_ensure` | on |
| `mcp_endpoint_compat` | on |

The plugin adds its folder to `fluent_boards/portal_asset_listed_slugs`, so its scripts also
run in the Fluent Boards frontend portal.

---

## Contact

nexTab – [nextab.de](https://nextab.de). If any of this gets fixed in core, we are happy to
remove our workaround and test the fix on our installation.

## License

Copyright (C) 2026 nexTab

This program is free software; you can redistribute it and/or modify it under the terms of the
GNU General Public License as published by the Free Software Foundation; either version 2 of the
License, or (at your option) any later version. See [LICENSE](LICENSE).
