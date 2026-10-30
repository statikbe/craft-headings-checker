# Release Notes for Headings Checker

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
