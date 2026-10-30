<?php

namespace statikbe\headingschecker\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\fields\Matrix;
use craft\htmlfield\HtmlField;
use statikbe\headingschecker\HeadingsChecker;
use statikbe\headingschecker\models\Settings;
use statikbe\trinity\services\Rows;
use statikbe\trinity\Trinity;

/**
 * The heading outline of a page: what the templates render (settings) plus the headings in the rich text fields.
 *
 * - The page's h1: settings → pageTitle, or the entry title.
 * - Rich text fields of the page itself (settings → textFields): under the h1, before the content builders.
 * - A title field of a block or column (settings → titleFields): an h2, also when "Show title" is off (then the templates
 *   render it for screen readers only).
 * - Cards (settings → cardTypes): h3.
 * - Rich text: the levels the editor picked.
 *
 * A row (Trinity) or block of cards with no h2 of its own before the cards gets a hidden h2 (rowHeading()), so the h3 cards
 * don't follow the h1 or an unrelated part of the page: when no h2 came before it on the page, or when it starts a new part
 * (another background than the row above; with Trinity also "Spacing above" normal or extra).
 *
 * A heading may go at most one level deeper than the heading above it (h2 → h4 skips h3), and only the page has an h1.
 * Without Trinity every block of the content builder is a row with one column, itself.
 */
class Outline extends Component
{
    /** Cache key of the report's version: raise it when the outline rules change, so the report is rebuilt */
    public const VERSION = 1;

    /**
     * @var \WeakMap<ElementInterface, array<string, array<string, mixed>>> outline per page object and field. A WeakMap, not
     * spl_object_id(): PHP reuses the id of a freed object, so another page could get its outline.
     */
    private \WeakMap $outlines;

    /** @var \WeakMap<Entry, array<string|null>> visible custom field handles per element object (without Trinity) */
    private \WeakMap $visibleFields;

    public function init(): void
    {
        parent::init();
        $this->outlines = new \WeakMap();
        $this->visibleFields = new \WeakMap();
    }

    /**
     * The hidden h2 above a row or block, or null when it doesn't need one.
     */
    public function rowHeading(ElementInterface $page, Entry $row, ?string $field = null): ?string
    {
        $field ??= $row->fieldId !== null ? $row->getField()->handle : ($this->settings()->fields[0] ?? '');
        $outlines = $this->outlines[$page] ?? [];
        $outlines[$field] ??= $this->outline($page, $field);
        $this->outlines[$page] = $outlines;
        return $outlines[$field]['rowHeadings'][$this->rowKey($row)] ?? null;
    }

    /**
     * Whether a block or column shows its title: the "Show title" lightswitch on, or not in its field layout.
     */
    public function showsTitle(Entry $block): bool
    {
        $field = $this->settings()->showTitleField;
        if ($field === null || $block->getFieldLayout()?->getFieldByHandle($field) === null) {
            return true;
        }
        return (bool)$block->getFieldValue($field);
    }

    /**
     * The content builder fields (settings → fields) in the entry's field layout.
     *
     * @return string[]
     */
    public function builderFields(ElementInterface $entry): array
    {
        $layout = $entry->getFieldLayout();
        return array_values(array_filter(
            $this->settings()->fields,
            fn(string $field) => $layout?->getFieldByHandle($field) instanceof Matrix,
        ));
    }

    /**
     * The rich text fields of the page itself (settings → textFields) in the entry's field layout.
     *
     * @return string[]
     */
    public function textFields(Entry $entry): array
    {
        $layout = $entry->getFieldLayout();
        return array_values(array_filter(
            $this->settings()->textFieldsFor($entry->getType()->handle),
            fn(string $field) => $layout?->getFieldByHandle($field) instanceof HtmlField,
        ));
    }

    /**
     * The heading issues of a page, its text fields and every content builder, or null when it has neither.
     *
     * @return string[]|null
     */
    public function issues(Entry $entry): ?array
    {
        $fields = $this->builderFields($entry);
        if (!$fields && !$this->textFields($entry)) {
            return null;
        }
        $issues = $this->textOutline($entry)['issues'];
        foreach ($fields as $field) {
            array_push($issues, ...$this->outline($entry, $field)['issues']);
        }
        return $issues;
    }

    /**
     * Every heading of a page in order: the h1, the headings of its text fields, then those of each content builder. Null
     * without text fields and content builders.
     *
     * @return array<array{level: int, text: string, kind: string, hidden: bool, issue: bool, row: int}>|null
     */
    public function pageTree(Entry $entry): ?array
    {
        $fields = $this->builderFields($entry);
        if (!$fields && !$this->textFields($entry)) {
            return null;
        }
        $tree = [[
            'level' => 1,
            'text' => $this->pageTitle($entry),
            'kind' => 'page',
            'hidden' => false,
            'issue' => false,
            'row' => 0,
        ]];
        array_push($tree, ...$this->textOutline($entry)['tree']);
        foreach ($fields as $field) {
            array_push($tree, ...$this->outline($entry, $field)['tree']);
        }
        return $tree;
    }

    /**
     * The heading outline of the page's text fields (settings → textFields), under the h1:
     * - issues: the headings that skip a level, as messages for the editor;
     * - fields: per issue (same order) the field it's in;
     * - tree: every heading in page order.
     *
     * @return array{issues: string[], fields: string[], tree: array<array{level: int, text: string, kind: string, hidden: bool, issue: bool, row: int}>}
     */
    public function textOutline(Entry $page): array
    {
        $result = ['issues' => [], 'fields' => [], 'tree' => []];
        $level = 1;
        foreach ($this->textFields($page) as $field) {
            if (!$this->layoutShowsField($page, $field)) {
                continue;
            }
            foreach (self::htmlHeadings((string)$page->getFieldValue($field)) as $heading) {
                $issue = $heading['level'] > $level + 1 || $heading['level'] === 1;
                if ($issue) {
                    $result['issues'][] = $this->textIssueMessage($page, $field, $heading, $level);
                    $result['fields'][] = $field;
                }
                $result['tree'][] = ['level' => $heading['level'], 'text' => $heading['text'], 'kind' => 'text', 'hidden' => false, 'issue' => $issue, 'row' => 0];
                $level = $heading['level'];
            }
        }
        return $result;
    }

    /**
     * The heading outline of a content builder field on a page:
     * - rowHeadings: the hidden h2 per row (by row id);
     * - issues: the headings that skip a level, as messages for the editor;
     * - places: per issue (same order) the row, the block or column and its field;
     * - tree: every heading in page order.
     *
     * @return array{rowHeadings: array<int, string>, issues: string[], places: array<array{row: Entry, cell: Entry, isRow: bool, field: string}>, tree: array<array{level: int, text: string, kind: string, hidden: bool, issue: bool, row: int}>}
     */
    public function outline(ElementInterface $page, string $field): array
    {
        $result = ['rowHeadings' => [], 'issues' => [], 'places' => [], 'tree' => []];
        if (!$page->getFieldLayout()?->getFieldByHandle($field) instanceof Matrix) {
            return $result;
        }

        $settings = $this->settings();
        $rows = $this->rows();
        $level = 1;
        $seenH2 = false;
        $previousBackground = null;
        $position = 0;

        foreach ($this->all($page->getFieldValue($field)) as $block) {
            $position++;
            $isRow = $rows !== null && $rows->isRow($block);
            // A row's columns; any other block is a row with one column, itself
            $cells = $isRow ? $this->all($block->getFieldValue($rows->getSettings()->cellsField)) : [$block];

            // A new part of the page: another background than the row above, or more spacing than "close" chosen
            $background = $this->background($block);
            $newPart = $position === 1 || $background !== $previousBackground || $this->extraSpacing($block);
            $previousBackground = $background;

            $cellInfo = [];
            foreach ($cells as $cell) {
                $cellInfo[] = [
                    'cell' => $cell,
                    'type' => $cell->getType()->handle,
                    'titles' => $this->titles($cell),
                    'headings' => $this->textHeadings($cell),
                ];
            }

            // The hidden h2 for a row whose cards come before any h2 of its own (a title, or an h2 in the text), in source order
            $cardColumn = null;
            foreach ($cellInfo as $info) {
                if ($info['titles'] || in_array(2, array_column($info['headings'], 'level'), true)) {
                    break;
                }
                if (in_array($info['type'], $settings->cardTypes, true)) {
                    $cardColumn = $info;
                    break;
                }
            }
            if ($cardColumn && (!$seenH2 || $newPart) && ($text = $this->defaultRowHeading($cardColumn['cell']))) {
                $result['rowHeadings'][$this->rowKey($block)] = $text;
                $result['tree'][] = ['level' => 2, 'text' => $text, 'kind' => 'row', 'hidden' => true, 'issue' => false, 'row' => $position];
                $level = 2;
                $seenH2 = true;
            }

            foreach ($cellInfo as $column => $info) {
                foreach ($info['titles'] as $title) {
                    $level = 2;
                    $seenH2 = true;
                    $result['tree'][] = [
                        'level' => 2,
                        'text' => $title,
                        'kind' => 'title',
                        'hidden' => !$this->showsTitle($info['cell']),
                        'issue' => false,
                        'row' => $position,
                    ];
                }
                foreach ($info['headings'] as $heading) {
                    $issue = $heading['level'] > $level + 1 || $heading['level'] === 1;
                    if ($issue) {
                        $result['issues'][] = $this->issueMessage($position, $isRow ? $column + 1 : null, $info['cell'], $heading, $level);
                        $result['places'][] = ['row' => $block, 'cell' => $info['cell'], 'isRow' => $isRow, 'field' => $heading['field']];
                    }
                    $result['tree'][] = ['level' => $heading['level'], 'text' => $heading['text'], 'kind' => 'text', 'hidden' => false, 'issue' => $issue, 'row' => $position];
                    $level = $heading['level'];
                    $seenH2 = $seenH2 || $level === 2;
                }
                if (in_array($info['type'], $settings->cardTypes, true)) {
                    $result['tree'][] = [
                        'level' => 3,
                        'text' => Craft::t('site', $info['cell']->getType()->name),
                        'kind' => 'cards',
                        'hidden' => false,
                        // h3 cards right under the h1: no title before them and no hidden heading configured
                        'issue' => $level < 2,
                        'row' => $position,
                    ];
                    if ($level >= 2) {
                        $level = 3;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * The headings in an HTML string, in order.
     *
     * @return array<array{level: int, text: string}>
     */
    public static function htmlHeadings(string $html): array
    {
        $headings = [];
        if (preg_match_all('/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $headings[] = [
                    'level' => (int)$match[1],
                    'text' => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5)) ?? ''),
                ];
            }
        }
        return $headings;
    }

    /**
     * The headings in the rich text fields a block or column shows, in order, also in its nested entries (e.g. the answers of
     * an FAQ; their titles aren't headings).
     *
     * @return array<array{level: int, text: string, field: string}> field: the field of the block or column it's in
     */
    private function textHeadings(Entry $cell, bool $nested = true): array
    {
        $headings = [];
        foreach ($cell->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if (!$this->showsField($cell, $field->handle)) {
                continue;
            }
            if ($field instanceof Matrix && $nested) {
                foreach ($this->all($cell->getFieldValue($field->handle)) as $entry) {
                    foreach ($this->textHeadings($entry, false) as $heading) {
                        $headings[] = ['field' => $field->handle] + $heading;
                    }
                }
                continue;
            }
            if (!$field instanceof HtmlField) {
                continue;
            }
            foreach (self::htmlHeadings((string)$cell->getFieldValue($field->handle)) as $heading) {
                $headings[] = $heading + ['field' => $field->handle];
            }
        }
        return $headings;
    }

    /**
     * The titles of a block or column (settings → titleFields, visible or hidden), without the empty ones and the ones its
     * layout hides.
     *
     * @return string[]
     */
    private function titles(Entry $cell): array
    {
        $titles = [];
        foreach ($this->settings()->titleFieldsFor($cell->getType()->handle) as $handle) {
            if ($cell->getFieldLayout()?->getFieldByHandle($handle) === null || !$this->showsField($cell, $handle)) {
                continue;
            }
            $title = trim((string)$cell->getFieldValue($handle));
            if ($title !== '') {
                $titles[] = $title;
            }
        }
        return $titles;
    }

    /**
     * The page's h1: the first settings → pageTitle path with a value, or the entry title.
     */
    private function pageTitle(Entry $entry): string
    {
        foreach ($this->settings()->pageTitlePaths() as $path) {
            $value = $entry;
            foreach (explode('.', $path) as $handle) {
                if (!$value instanceof ElementInterface || $value->getFieldLayout()?->getFieldByHandle($handle) === null) {
                    $value = null;
                    break;
                }
                $value = $value->getFieldValue($handle);
                if ($value instanceof ElementQueryInterface) {
                    $value = $this->all($value)[0] ?? null;
                }
            }
            $text = is_scalar($value) || $value instanceof \Stringable ? trim((string)$value) : '';
            if ($text !== '') {
                return $text;
            }
        }
        return (string)$entry->title;
    }

    /**
     * The hidden h2's text for a block or column of cards (settings → rowHeadings). Null when none is configured.
     */
    private function defaultRowHeading(Entry $cell): ?string
    {
        $settings = $this->settings();
        $heading = $settings->rowHeadings[$cell->getType()->handle] ?? null;
        if (is_callable($heading)) {
            $text = $heading($cell);
            return is_string($text) && $text !== '' ? $text : null;
        }
        return is_string($heading) && $heading !== '' ? Craft::t($settings->rowHeadingCategory, $heading) : null;
    }

    /**
     * The row's background as the templates use it: Trinity's background field (or its default when it's empty or hidden), or
     * settings → backgroundField.
     */
    private function background(Entry $block): string
    {
        $rows = $this->rows();
        if ($rows !== null) {
            $trinity = $rows->getSettings();
            $field = $trinity->rowBackgroundField;
            if (!$field || $block->getFieldLayout()?->getFieldByHandle($field) === null) {
                return '';
            }
            $value = $rows->showsField($block, $field) ? (string)$block->getFieldValue($field) : '';
            return $value !== '' ? $value : (string)$trinity->rowBackgroundDefault;
        }
        $field = $this->settings()->backgroundField;
        if (!$field || $block->getFieldLayout()?->getFieldByHandle($field) === null) {
            return '';
        }
        return (string)$block->getFieldValue($field);
    }

    /**
     * Whether a Trinity row has more spacing above than "close" (normal, extra).
     */
    private function extraSpacing(Entry $block): bool
    {
        $rows = $this->rows();
        $field = $rows?->getSettings()->rowSpacingField;
        if (!$field || $block->getFieldLayout()?->getFieldByHandle($field) === null) {
            return false;
        }
        $value = $block->getFieldValue($field);
        return in_array((string)(is_object($value) && isset($value->value) ? $value->value : $value), ['normal', 'extra'], true);
    }

    /**
     * @param array{level: int, text: string} $heading
     */
    private function issueMessage(int $row, ?int $column, Entry $cell, array $heading, int $level): string
    {
        $type = Craft::t('site', $cell->getType()->name);
        $where = $column !== null
            ? Craft::t('headings-checker', 'Row {row}, column {column} ({type})', ['row' => $row, 'column' => $column, 'type' => $type])
            : Craft::t('headings-checker', 'Block {row} ({type})', ['row' => $row, 'type' => $type]);
        $params = ['text' => $heading['text'], 'level' => $heading['level'], 'above' => $level, 'next' => $level + 1];

        if ($heading['level'] === 1) {
            $problem = Craft::t('headings-checker', 'the heading “{text}” is an H1, but the page title is the only H1.', $params);
        } elseif ($level === 1) {
            $hasTitleField = (bool)array_filter(
                $this->settings()->titleFieldsFor($cell->getType()->handle),
                fn(string $handle) => $cell->getFieldLayout()?->getFieldByHandle($handle) !== null,
            );
            $problem = match (true) {
                $hasTitleField && $column !== null => Craft::t('headings-checker', 'the heading “{text}” is an H{level}, but there is no H2 above it. Give this column a title.', $params),
                $hasTitleField => Craft::t('headings-checker', 'the heading “{text}” is an H{level}, but there is no H2 above it. Give this block a title.', $params),
                default => Craft::t('headings-checker', 'the heading “{text}” is an H{level}, but there is no H2 above it. Add a title above it.', $params),
            };
            $showTitle = $this->settings()->showTitleField;
            $layout = $cell->getFieldLayout();
            if ($showTitle !== null && $layout?->getFieldByHandle($showTitle) !== null) {
                $problem .= ' ' . Craft::t('headings-checker', 'Switch off “{label}” to only show the title to screen readers.', [
                    'label' => Craft::t('site', $layout->getField($showTitle)->label() ?? $showTitle),
                ]);
            }
        } else {
            $problem = Craft::t('headings-checker', 'the heading “{text}” is an H{level}, but the heading above it is an H{above}. Make it an H{next}.', $params);
        }
        return $where . ': ' . $problem;
    }

    /**
     * An issue in a text field of the page: the heading above it is the h1 or a heading of the text.
     *
     * @param array{level: int, text: string} $heading
     */
    private function textIssueMessage(Entry $page, string $field, array $heading, int $level): string
    {
        $label = $page->getFieldLayout()?->getField($field)->label() ?? $field;
        $where = Craft::t('headings-checker', 'Field “{field}”', ['field' => Craft::t('site', $label)]);
        $params = ['text' => $heading['text'], 'level' => $heading['level'], 'above' => $level, 'next' => $level + 1];
        $problem = $heading['level'] === 1
            ? Craft::t('headings-checker', 'the heading “{text}” is an H1, but the page title is the only H1.', $params)
            : Craft::t('headings-checker', 'the heading “{text}” is an H{level}, but the heading above it is an H{above}. Make it an H{next}.', $params);
        return $where . ': ' . $problem;
    }

    /**
     * Whether a field of a block or column is shown: Trinity's check (it knows the column conditions), or the field layout's
     * conditions.
     */
    private function showsField(Entry $element, string $handle): bool
    {
        $rows = $this->rows();
        if ($rows !== null) {
            return $rows->showsField($element, $handle);
        }
        return $this->layoutShowsField($element, $handle);
    }

    /**
     * Whether the field layout's conditions show a field of an element.
     */
    private function layoutShowsField(Entry $element, string $handle): bool
    {
        $this->visibleFields[$element] ??= array_map(
            fn($field) => $field->handle,
            $element->getFieldLayout()?->getVisibleCustomFields($element) ?? [],
        );
        return in_array($handle, $this->visibleFields[$element], true);
    }

    /**
     * Trinity's rows service, when Trinity is installed and enabled.
     */
    public function rows(): ?Rows
    {
        if (!class_exists(Trinity::class) || !Craft::$app->getPlugins()->isPluginEnabled('trinity')) {
            return null;
        }
        return Trinity::getInstance()->getRows();
    }

    /**
     * The enabled entries of a Matrix value: an array, an eager-loaded collection, or a query; a query's cached result first
     * (the entries as edited, while validating a save), as Craft's Matrix field does.
     *
     * @return Entry[]
     */
    public function all(mixed $value): array
    {
        if ($value instanceof ElementQueryInterface) {
            $cached = $value->getCachedResult();
            // Disabled entries aren't rendered
            $elements = $cached !== null
                ? array_filter($cached, fn($e) => $e->enabled && $e->getEnabledForSite())
                : (clone $value)->all();
        } elseif ($value === null) {
            $elements = [];
        } else {
            $elements = is_array($value) ? $value : (method_exists($value, 'all') ? $value->all() : iterator_to_array($value));
        }
        return array_values(array_filter($elements, fn($element) => $element instanceof Entry));
    }

    private function rowKey(Entry $row): int
    {
        return $row->id ?? spl_object_id($row);
    }

    private function settings(): Settings
    {
        /** @var Settings */
        return HeadingsChecker::getInstance()->getSettings();
    }
}
