# Release Notes for Headings Checker

## 1.3.0 - 2026-10-31

> [!IMPORTANT]
> Run `php craft up` after updating: a migration adds the table of saved pages to check. The widget's list is built again once.

### Changed
- The Heading issues widget sorts, counts and cuts its list in the database and only loads the pages it shows: on a site with 7,549 pages with issues, 40–70 ms and 2 MB instead of 0.6 s and 150 MB (it could run out of memory on a large site). Pages are sorted by title as the database sorts them (case and accents don't matter)
- Saved pages are checked by a queue job after the request (`UpdatePagesJob`), a batch per site, instead of one by one at the end of the editor's request. A bulk save of 400 pages took 2.5 s in the request; it now takes 20 ms. Resaving (`craft resave`) no longer checks pages again
- Pages saved by a queue job (an import) are queued for the check right after that job, also when the queue runs in a web request
- The list is only built again when the settings, Trinity's settings, or the fields, entry types, sections or sites in the project config change, not after every project config change (an image transform, a user group, another plugin's settings)
- While the list is built again, the previous one stays visible, with a note that it's being updated
- A build checks only the entries of entry types with a content builder or a text field, a site at a time in order of id (no offset), in batches of 25 pages, and goes on in a new job well before the time limit. A newer build stops an older one; a stalled build no longer runs twice. On the base install: 4.2 s and 760 queries instead of 5.7 s and 5,400; 40 MB instead of 100 MB
- The plugin's queue jobs run after Craft's own (priority 2048) with a time limit
- `craft.headingsChecker.rowHeading()` only reads the page's outline for a row with cards that have a hidden h2 configured; on a page without such rows it costs nothing
- The Heading outline panel loads its list after the page, and reloads it 10 seconds after the editor's last autosave (a minute while it's collapsed, at once when it's expanded), cancelling a reload that's still running
- `headings-checker/check` reads the pages in batches (not all into memory) and is about a third faster

## 1.2.0 - 2026-10-30

### Changed
- The Heading issues widget's list is kept in a table (one row per page and site) instead of the cache, and built by a queue job when the settings or the project config change: never while the dashboard loads, and clearing the caches no longer rebuilds it. The widget shows "Building the list…" until it's ready
- Two editors saving at the same time no longer overwrite each other's update of the list
- The list also follows changes of `backgroundField`, Trinity's settings and the field layouts (project config)
- Issues are stored as data and turned into messages when they're shown, in the reader's language; links and titles are current
- Rows, columns and nested entries are loaded in one query per level for the pages fetched together (about a third of the queries)
- `headings-checker/check` reads every page once (it read them twice without `--section`) and exits with 1 when a page has issues
- Pages saved by a queue job (e.g. an import) update the widget right after the job, not when the queue worker stops

### Fixed
- The Heading outline panel works for an entry opened in a slideout, next to the panel of the page behind it
- The help text of `headings-checker/check`

## 1.1.2 - 2026-10-30

### Fixed
- A page is read in one pass: a heading in a text field (`textFields`) counts for the content builder below it, and a second content builder goes on where the first one stopped, instead of each starting again under the h1
- Cards right under the h1 (no title before them and no hidden h2 from `rowHeadings`) are an issue everywhere: on publish, in the widget and in `headings-checker/check`, not only in the outline panel
- `textFields` only applies to pages (entries in a section), not to rows, blocks or columns with a field of the same handle; the outline panel is only shown for pages
- Markdown in a heading's text no longer becomes a link or an image in the error messages (Craft renders validation errors as Markdown)
- The Heading issues widget also checks the site and "Edit other authors' entries" permissions, and leaves out deleted pages
- The outline panel's reload checks that the user may edit the site
- A page deleted for one site, or restored from the trash, updates the widget's list
- A page saved late in a request could miss the widget's list
- `mode` is validated: a typo such as `'Block'` no longer switches the check off (case doesn't matter; an unknown value is logged and counts as `'warn'`)
- Row numbers in the messages count disabled rows too, as the editor sees them
- Rich text is read as stored: nested entries in CKEditor aren't rendered and embeds aren't fetched while checking
- Content builders hidden by a field layout condition aren't checked
- A plain Matrix block with an issue is marked in cards view too

## 1.1.1 - 2026-10-30

### Changed
- The Heading issues widget only lists the pages in sections the user may edit (an admin still sees every page)

## 1.1.0 - 2026-10-30

### Added
- `textFields`: rich text fields of the entry itself, outside a content builder, checked under the h1 (also for entries without a content builder)

### Changed
- In `'warn'` mode the warning lists the issues of every content builder, instead of only the last one with issues

### Fixed
- `headings-checker/check` and the widget's report could skip or report the wrong blocks on large sites: a block could get the visible fields of another one, freed earlier, whose object id PHP reused

## 1.0.0 - 2026-10-30

### Added
- Heading check when an entry is published (`mode`: `'block'`, `'warn'` or `false`), with the error on the row, the block or column and its field
- Heading outline panel in the entry sidebar (`outline`), collapsible, reloaded after an autosave
- Heading issues dashboard widget and `headings-checker/check` console command
- Level badges in front of the headings in rich text fields (`levelBadges`)
- `craft.headingsChecker.showsTitle()`, `rowHeading()` and `outline()`
- Plain Matrix content builders and Trinity rows of columns
