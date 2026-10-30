# Headings Checker

Screen readers and search engines read a page by its headings, so the levels may not skip one: an h2 followed by an h4
leaves out the h3. Headings Checker checks the heading order of your content builders (Matrix fields) in Craft CMS 5:

- **When an entry is published**: block the save, or warn, when a heading in the content skips a level. With `'block'` the
  error is shown where the problem is, as for Craft's own errors: on the row, the block or column, and its field.
- **Heading outline** in the entry sidebar: every heading of the page in order, indented by level, with hidden headings and
  skipped levels marked. Collapsible (the browser remembers it) and reloaded after every autosave.
- **Heading issues** dashboard widget: the pages with issues, with a link to edit each one.
- **`craft headings-checker/check`**: lists the entries with issues, e.g. before you switch to `'block'` on a site with
  existing content.
- **Level badges** in rich text fields (CKEditor, Redactor): H2, H3, … in front of every heading, so editors see the level
  they picked.
- **Twig helpers** for a title that's only for screen readers, and for a hidden h2 above a row of cards.

It works with plain Matrix content builders (one entry per block) and with [Trinity](https://github.com/statikbe/craft-trinity)
rows of columns: when Trinity is installed, the checker finds the rows and columns by itself.

## Requirements

Craft CMS 5.6 or later, PHP 8.2 or later.

## Installation

```bash
composer require statikbe/craft-headings-checker
php craft plugin/install headings-checker
```

## How it reads a page

The checker can't see your templates, so the settings tell it which headings they render. The headings in rich text fields
it reads itself.

| Heading | Level | Setting |
| --- | --- | --- |
| The page title | h1 | `pageTitle`: the entry title, or a field such as `hero.heading` |
| The title of a block or column | h2 | `titleFields`: e.g. `blockTitle` |
| Cards of a block or column (an overview, a team) | h3 | `cardTypes`: the entry type handles |
| Headings in rich text fields | the level the editor picked | read from the content, also in nested entries (e.g. FAQ answers) |

A heading may go at most one level deeper than the heading above it, counting the titles, and only the page has an h1. Blocks
and columns are read in order; disabled ones are skipped, as are fields that a field layout condition hides.

## Configuration

Copy this to `config/headings-checker.php` and change what your templates need. Multi-environment configs work as usual.

```php
<?php

return [
    // 'block': a heading that skips a level stops the entry from being published, shown on the row, block and field.
    // 'warn': it's saved, with a warning. false: no check.
    'mode' => 'warn',

    // The Matrix fields that are content builders
    'fields' => ['contentBuilder'],

    // Where the h1 comes from: field paths, the first one with a value wins (a field of a Content Block or of the first entry of
    // a Matrix field). Empty: the entry title.
    'pageTitle' => ['hero.heading'],

    // Fields the templates render as an h2 title of a block or column. One list for every entry type, or per entry type handle
    // with '*' for the rest.
    'titleFields' => [
        '*' => ['blockTitle'],
        'textTwoColumns' => ['titleColumn1', 'titleColumn2'],
    ],

    // A lightswitch next to the title: off, the templates show the title to screen readers only (it's still an h2).
    'showTitleField' => 'showTitle',

    // Entry types whose cards the templates render as h3 headings
    'cardTypes' => ['overview', 'cellOverview', 'cellTeam'],

    // The hidden h2 above a row or block of cards without an h2 before the cards, per entry type: a text, a translation key
    // (rowHeadingCategory), or a function that gets the block or column.
    'rowHeadings' => [
        'cellTeam' => 'Team',
        'cellOverview' => fn($cell) => Craft::t('site', 'Overview'),
    ],
    'rowHeadingCategory' => 'site',

    // Without Trinity: a block with another background than the one above starts a new part of the page (and gets its own
    // hidden h2 above cards). With Trinity, its rowBackgroundField and rowSpacingField are used.
    'backgroundField' => null,

    // The Heading outline panel in the entry sidebar
    'outline' => true,

    // H2, H3, … badges in front of the headings in rich text fields in the control panel
    'levelBadges' => true,
];
```

### Plain Matrix content builder

Every block is checked as a row with one column: its title fields, its rich text fields and its nested entries. An issue is
shown on the block's field in the blocks view.

### Trinity rows

With [Trinity](https://github.com/statikbe/craft-trinity) installed, a row's columns are read left to right. An issue is
shown on the row (under its columns) and on the column's card, which turns red. The column conditions of Trinity are taken
into account, and a row with another background, or with more spacing above than "close", starts a new part of the page.

## Templates

### A title only for screen readers

With `showTitleField`, a block can keep its title as a heading without showing it:

```twig
{% set hideTitle = not craft.headingsChecker.showsTitle(block) %}
<h2 class="{{ hideTitle ? 'sr-only' }}">{{ block.blockTitle }}</h2>
```

### The hidden h2 above cards

A row of cards (h3) without a title puts its cards right under the h1, or under the h2 of an unrelated part of the page.
`rowHeading()` returns a hidden h2 for such a row, from `rowHeadings`, or null when the row doesn't need one:

```twig
{% set rowHeading = craft.headingsChecker.rowHeading(entry, block) %}
{% if rowHeading %}
  <h2 class="sr-only">{{ rowHeading }}</h2>
{% endif %}
```

A row gets one when its cards come before any h2 of its own, and either no h2 came before it on the page or it starts a new
part of the page.

### The outline

`craft.headingsChecker.outline(entry)` returns every heading of the page in order (`level`, `text`, `kind`, `hidden`, `issue`).

## Console

```bash
php craft headings-checker/check                  # every section, every site
php craft headings-checker/check --section=pages
```

Without `--section` it also rebuilds the list of the dashboard widget.

## Notes

- The check only runs when an entry is published in a web request: not for drafts, autosaves, resaving, propagation
  to other sites or console commands.
- The widget's list is kept in the cache and updated when a page or one of its blocks is saved or deleted. A changed config
  starts a new list.

## License

MIT, see [LICENSE.md](LICENSE.md).
