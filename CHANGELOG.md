# Release Notes for Headings Checker

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
