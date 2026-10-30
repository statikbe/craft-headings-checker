<?php

namespace statikbe\headingschecker\models;

use craft\base\Model;

/**
 * Settings, in config/headings-checker.php. The checker can't see the templates, so the settings tell it which headings they
 * render: the h1, a title field as an h2, cards as an h3. The headings in rich text fields (CKEditor, Redactor) it reads itself.
 */
class Settings extends Model
{
    /**
     * @var string|false What happens when an entry is published with a heading that skips a level:
     * - 'block': it isn't saved; the error is shown on the row, the block or column and its field, as for Craft's own errors;
     * - 'warn': it's saved, with a warning listing the issues;
     * - false: no check.
     */
    public string|false $mode = 'warn';

    /** @var string[] Handles of the Matrix fields that are content builders */
    public array $fields = ['contentBuilder'];

    /**
     * @var string|string[]|null Where the page's h1 comes from: a field path such as 'hero.heading' (a field of a Content Block
     * or of the first entry of a Matrix field), or several, the first one with a value wins. The entry title when empty.
     */
    public string|array|null $pageTitle = null;

    /**
     * @var array<string, string[]>|string[] Fields of a block or column that the templates render as an h2 title. A list for
     * every entry type, or per entry type handle with '*' for the rest:
     * ['*' => ['blockTitle'], 'textTwoColumns' => ['titleColumn1', 'titleColumn2']].
     */
    public array $titleFields = ['blockTitle'];

    /**
     * @var string|null A lightswitch next to the title: off, the templates render the title for screen readers only. Still an
     * h2 in the outline, marked hidden. Null: titles are always shown.
     */
    public ?string $showTitleField = 'showTitle';

    /** @var string[] Entry type handles of blocks or columns whose cards the templates render as h3 headings */
    public array $cardTypes = [];

    /**
     * @var array<string, string|callable> The hidden h2 above a row (or block) of cards that has no h2 of its own before the
     * cards, per entry type handle of the cards block or column: a text or a translation key (rowHeadingCategory), or a
     * function(Entry $block): ?string. The templates render it with craft.headingsChecker.rowHeading(entry, row).
     */
    public array $rowHeadings = [];

    /** @var string Translation category of the rowHeadings texts */
    public string $rowHeadingCategory = 'site';

    /**
     * @var string|null Without Trinity: the field with a block's background. A block with another background than the one
     * above starts a new part of the page, which gets its own hidden h2 above cards. With Trinity, its rowBackgroundField
     * and rowSpacingField are used.
     */
    public ?string $backgroundField = null;

    /** @var bool The "Heading outline" panel in the sidebar of entries with a content builder */
    public bool $outline = true;

    /** @var bool The heading level (H2, H3, …) as a badge in front of every heading in rich text fields in the control panel */
    public bool $levelBadges = true;

    protected function defineRules(): array
    {
        return [
            [['fields', 'titleFields', 'cardTypes', 'rowHeadings'], 'safe'],
            [['outline', 'levelBadges'], 'boolean'],
        ];
    }

    /**
     * The title fields of an entry type.
     *
     * @return string[]
     */
    public function titleFieldsFor(string $entryType): array
    {
        if (array_is_list($this->titleFields)) {
            return $this->titleFields;
        }
        return $this->titleFields[$entryType] ?? $this->titleFields['*'] ?? [];
    }

    /**
     * The field paths of the page's h1.
     *
     * @return string[]
     */
    public function pageTitlePaths(): array
    {
        return array_values(array_filter((array)$this->pageTitle));
    }
}
