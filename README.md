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
- **Published checkbox.** The Gin admin theme relocates the "Published"
  checkbox into markup that only its own page/form templates print.
  Mercury Editor renders neither, so the checkbox silently disappears
  from the editor tray; this module puts it back.
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

The active theme must define two libraries, named after the theme (e.g.
for a theme called `mytheme`: `mytheme/edit_screen` and
`mytheme/mercury_editor_preview`):

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
