# EDW Mercury Editor

Overrides for Drupal [Mercury Editor](https://www.drupal.org/project/mercury_editor).

## Why

Mercury Editor's tempstore-backed editing session has a few rough edges on
multi-editor sites. This module patches them:

- **Concurrent-edit detection.** Mercury Editor forces the entity's
  `changed` timestamp on every form validate, which defeats Drupal core's
  optimistic-locking check. This module restores it: it swaps in a param
  converter that keeps the tempstore's original base version on a save
  request, and a form validation handler that compares it against the
  live database row, so a user gets "content has been updated by another
  user" instead of silently overwriting someone else's edit. On a plain
  read request it does the opposite — a stale tempstore entry is
  discarded in favor of a newer database version (e.g. saved via the
  standard edit form, a bulk action, or another user), and a tempstore
  entry pointing at a deleted entity 404s instead of resurrecting it.
- **Admin-theme-relocated fields.** The Gin admin theme relocates the
  "Published" checkbox into markup that only its own page/form templates
  print. Mercury Editor renders neither, so the checkbox silently
  disappears from the editor tray; this module puts back any field caught
  the same way, where the form display places it (only Gin's "Published"
  relocation is currently known and handled, but the fix isn't hardcoded
  to that one field).
- **State dropdown.** The publishing state - the moderation state on
  bundles in a workflow, the "Published" checkbox otherwise - is shown as a
  dropdown in the editor toolbar, left of "Save", so it stays visible with
  the tray collapsed. Picking a state only takes effect on "Save"; a dot on
  the dropdown marks an unsaved change. The field itself stays in the tray
  form, hidden, wherever the form display puts it.
- **Publishing group.** Every Mercury Editor form gets one "Publishing"
  accordion in the sidebar, ending with "Last saved", "Create new revision"
  and the revision log. A form display field group with the machine name
  `group_publishing` is used as that group, so its label, position and
  fields are set in Manage form display. Without one, the module adds the
  group itself (sidebar weight 30) with "Authored by" and "Authored on",
  unless the form display already put those in another field group. The URL
  alias keeps its own sidebar accordion.
- **No duplicate publishing controls.** The sidebar's read-only state and
  author lines are hidden when the toolbar dropdown and the "Authored by"
  field already show them. content_moderation's own "Change to … Apply"
  form is dropped from the preview, because it would save the stored
  revision and skip the tray.
- **Toolbar field groups.** A field group with the "Mercury Editor toolbar"
  format (Manage form display, requires field_group) becomes a button in the
  editor toolbar, left of the state dropdown, that opens a panel with the
  group's fields. Add as many as needed; label, fields and order all come
  from the form display. The fields stay in the tray form, so "Save" submits
  them; a dot on the button marks an unsaved change (orange) or a field the
  last save rejected (red), and "Save" opens the panel of a required field
  left empty. Widgets that file themselves elsewhere (the URL alias goes to
  the sidebar) stay in the group. Outside the Mercury Editor the group is a
  plain details element. Widgets that put popups on `<body>` outside jQuery UI (e.g.
  CKEditor balloons) are hidden behind an open panel, so keep rich text
  fields out of toolbar groups.
- **Admin toolbar.** Mercury Editor hides the admin toolbar on its editor
  route by default; this module keeps it visible so editors retain normal
  site navigation while editing.
- **404/403 chrome.** Suppresses Mercury Editor's page template on system
  error pages, where it doesn't apply.
- **Component attributes audit.** Warns (log, plus an on-page banner when
  Twig debug is enabled) when a Layout Paragraphs component's Twig
  template doesn't forward the `attributes` variable — Layout
  Paragraphs/Mercury Editor need it to track the component for
  drag-reorder, edit and save.
- **View button.** Replaces the Mercury Editor logo in the editor toolbar
  with a "View" button that opens the entity's live canonical page in a new
  tab. Unlike "Done" — which navigates away and discards any tray edits
  never persisted through the toolbar "Save" — this is a safe way to check
  the current published page without leaving the editing session. Only
  shown once the entity has been saved at least once.

This module was extracted from a project-specific `edw_mercury_editor`
module.

## Installation

Add this repository to your project's `composer.json` and require it:

```json
"repositories": [
    {"type": "vcs", "url": "https://github.com/eaudeweb/edw_mercury_editor.git"}
]
```

```
composer require eaudeweb/edw_mercury_editor
drush en edw_mercury_editor
```

## Requirements

The site's themes must define two libraries, named after the theme (e.g.
for a theme called `mytheme`: `mytheme/edit_screen` and
`mytheme/mercury_editor_preview`). `edit_screen` is loaded from the default
(frontend) theme even though the editor page renders under the admin
theme; `mercury_editor_preview` from the theme the preview renders under,
normally also the default theme:

- `edit_screen`: styles for the editor chrome — toolbar, dialogs and the
  tray around the preview iframe. Attached to the `page--mercury-editor`
  page.
- `mercury_editor_preview`: styles for the document rendered *inside*
  the preview iframe (e.g. rules targeting Layout Paragraphs builder
  markup like `.js-lpb-component`). Attached only while
  `mercury_editor.context` reports the current request as a preview,
  since the preview is a separate document from the editor chrome.

If a theme doesn't define these libraries, Drupal silently drops the
missing library from the page's asset list — no error, no log entry.
The editor and preview just render unstyled until the libraries are
added.
