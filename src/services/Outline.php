<?php

namespace statikbe\headingschecker\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\fields\Matrix;
use craft\htmlfield\HtmlField;
use craft\htmlfield\HtmlFieldData;
use statikbe\headingschecker\HeadingsChecker;
use statikbe\headingschecker\helpers\HeadingOrder;
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
 * The page is read in one pass (helpers\HeadingOrder has the rules): the text fields, then each content builder, so a heading
 * goes on from the one above it, wherever that is. Without Trinity every block of the content builder is a row with one
 * column, itself.
 */
class Outline extends Component
{
    /** Version of the outline rules: raise it when they change, so the report is rebuilt */
    public const VERSION = 2;

    /**
     * @var \WeakMap<ElementInterface, array<string, mixed>> outline per page object. A WeakMap, not spl_object_id(): PHP reuses
     * the id of a freed object, so another page could get its outline.
     */
    private \WeakMap $outlines;

    /** @var \WeakMap<ElementInterface, array<string|null>> visible custom field handles per element object (without Trinity) */
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
        return $this->pageOutline($page)['rowHeadings'][$field][$this->rowKey($row)] ?? null;
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
     * The content builder fields (settings → fields) in the entry's field layout, that its conditions show.
     *
     * @return string[]
     */
    public function builderFields(ElementInterface $entry): array
    {
        $layout = $entry->getFieldLayout();
        return array_values(array_filter(
            $this->settings()->fields,
            fn(string $field) => $layout?->getFieldByHandle($field) instanceof Matrix && $this->layoutShowsField($entry, $field),
        ));
    }

    /**
     * The rich text fields of the page itself (settings → textFields) in the entry's field layout, that its conditions show.
     * Only for pages (entries in a section): not for rows, blocks or columns that happen to have a field with that handle.
     *
     * @return string[]
     */
    public function textFields(ElementInterface $entry): array
    {
        if (!$entry instanceof Entry || $entry->getSection() === null) {
            return [];
        }
        $layout = $entry->getFieldLayout();
        return array_values(array_filter(
            $this->settings()->textFieldsFor($entry->getType()->handle),
            fn(string $field) => $layout?->getFieldByHandle($field) instanceof HtmlField && $this->layoutShowsField($entry, $field),
        ));
    }

    /**
     * The heading issues of a page, as messages, or null when it has no text fields and no content builder.
     *
     * @return string[]|null
     */
    public function issues(Entry $entry): ?array
    {
        $outline = $this->pageOutline($entry);
        if (!$outline['applies']) {
            return null;
        }
        return array_map(fn(array $issue) => $this->message($issue), $outline['issues']);
    }

    /**
     * Every heading of a page in order: the h1, the headings of its text fields, then those of each content builder. Null
     * without text fields and content builders.
     *
     * @return array<array{level: int, text: string, kind: string, hidden: bool, issue: bool, row: int}>|null
     */
    public function pageTree(Entry $entry): ?array
    {
        $outline = $this->pageOutline($entry);
        return $outline['applies'] ? $outline['tree'] : null;
    }

    /**
     * The heading outline of a page, in one pass over its text fields and content builders:
     * - applies: whether it has text fields or a content builder;
     * - tree: every heading in page order, the h1 first;
     * - issues: the headings (and cards) that skip a level, as data for message();
     * - places: per issue (same order) where it is: the field of the page, and in a content builder the row, the block or
     *   column and its field (null: none to put it on);
     * - rowHeadings: the hidden h2 per content builder field and row (rowKey()).
     *
     * Kept per page object; $fresh reads the page again (its values changed, e.g. while validating a save).
     *
     * @return array{
     *     applies: bool,
     *     tree: array<array{level: int, text: string, kind: string, hidden: bool, issue: bool, row: int}>,
     *     issues: array<array<string, mixed>>,
     *     places: array<array{field: string, row: Entry|null, cell: Entry|null, isRow: bool, cellField: string|null}>,
     *     rowHeadings: array<string, array<int, string>>,
     * }
     */
    public function pageOutline(ElementInterface $page, bool $fresh = false): array
    {
        if (!$fresh && isset($this->outlines[$page])) {
            return $this->outlines[$page];
        }
        if ($fresh) {
            unset($this->visibleFields[$page]);
        }

        $parts = [];
        $meta = [];
        foreach ($this->textFields($page) as $field) {
            $parts[] = ['kind' => 'text', 'headings' => self::htmlHeadings($this->html($page->getFieldValue($field)))];
            $meta[] = ['field' => $field, 'text' => true];
        }
        $builders = $this->builderFields($page);
        foreach ($builders as $field) {
            [$part, $rowsMeta] = $this->builderPart($page, $field);
            $parts[] = $part;
            $meta[] = ['field' => $field, 'text' => false, 'rows' => $rowsMeta];
        }

        $walk = HeadingOrder::walk($parts);
        $result = [
            'applies' => (bool)$parts,
            'tree' => [[
                'level' => 1,
                'text' => $page instanceof Entry ? $this->pageTitle($page) : (string)$page,
                'kind' => 'page',
                'hidden' => false,
                'issue' => false,
                'row' => 0,
            ]],
            'issues' => [],
            'places' => [],
            'rowHeadings' => [],
        ];

        foreach ($walk['tree'] as $node) {
            $rowMeta = $node['row'] !== null ? $meta[$node['part']]['rows'][$node['row']] : null;
            $text = $node['text'];
            if ($node['kind'] === 'cards') {
                $text = Craft::t('site', $rowMeta['cells'][$node['cell']]['cell']->getType()->name);
            }
            $result['tree'][] = [
                'level' => $node['level'],
                'text' => $text,
                'kind' => $node['kind'],
                'hidden' => $node['hidden'],
                'issue' => $node['issue'],
                'row' => $rowMeta['position'] ?? 0,
            ];
        }

        foreach ($walk['rowHeadings'] as $p => $rows) {
            foreach ($rows as $r => $text) {
                $result['rowHeadings'][$meta[$p]['field']][$this->rowKey($meta[$p]['rows'][$r]['block'])] = $text;
            }
        }

        foreach ($walk['issues'] as $issue) {
            $partMeta = $meta[$issue['part']];
            if ($partMeta['text']) {
                $result['issues'][] = $this->textIssue($page, $partMeta['field'], $issue);
                $result['places'][] = ['field' => $partMeta['field'], 'row' => null, 'cell' => null, 'isRow' => false, 'cellField' => $partMeta['field']];
                continue;
            }
            $rowMeta = $partMeta['rows'][$issue['row']];
            $cellMeta = $rowMeta['cells'][$issue['cell']];
            $result['issues'][] = $this->builderIssue($rowMeta, $cellMeta, $issue);
            $result['places'][] = [
                'field' => $partMeta['field'],
                'row' => $rowMeta['block'],
                'cell' => $cellMeta['cell'],
                'isRow' => $rowMeta['isRow'],
                'cellField' => $issue['heading'] !== null
                    ? $cellMeta['headingFields'][$issue['heading']]
                    : $this->cardsField($cellMeta['cell']),
            ];
        }

        return $this->outlines[$page] = $result;
    }

    /**
     * An issue as a message for the editor. $markdown: Markdown characters in the page's own texts (the heading, labels) are
     * escaped, for messages that Craft renders as Markdown (validation errors), so they can't become links or images.
     *
     * @param array<string, mixed> $issue
     */
    public function message(array $issue, bool $markdown = false): string
    {
        $escape = fn(string $text) => $markdown ? self::escapeMarkdown($text) : $text;
        $site = fn(?string $text) => $escape(Craft::t('site', (string)$text));

        $where = match ($issue['where']) {
            'field' => Craft::t('headings-checker', 'Field “{field}”', ['field' => $site($issue['field'])]),
            'column' => Craft::t('headings-checker', 'Row {row}, column {column} ({type})', ['row' => $issue['row'], 'column' => $issue['column'], 'type' => $site($issue['type'])]),
            default => Craft::t('headings-checker', 'Block {row} ({type})', ['row' => $issue['row'], 'type' => $site($issue['type'])]),
        };
        $params = [
            'text' => $escape((string)$issue['text']),
            'level' => $issue['level'],
            'above' => $issue['above'],
            'next' => $issue['above'] + 1,
        ];
        $problem = match ($issue['problem']) {
            'h1' => Craft::t('headings-checker', 'the heading “{text}” is an H1, but the page title is the only H1.', $params),
            'noH2' => match ($issue['hint']) {
                'column' => Craft::t('headings-checker', 'the heading “{text}” is an H{level}, but there is no H2 above it. Give this column a title.', $params),
                'block' => Craft::t('headings-checker', 'the heading “{text}” is an H{level}, but there is no H2 above it. Give this block a title.', $params),
                default => Craft::t('headings-checker', 'the heading “{text}” is an H{level}, but there is no H2 above it. Add a title above it.', $params),
            },
            'cards' => match ($issue['hint']) {
                'column' => Craft::t('headings-checker', 'the cards are H3 headings, but there is no H2 above them. Give this column a title.'),
                'block' => Craft::t('headings-checker', 'the cards are H3 headings, but there is no H2 above them. Give this block a title.'),
                default => Craft::t('headings-checker', 'the cards are H3 headings, but there is no H2 above them. Add a title above them.'),
            },
            default => Craft::t('headings-checker', 'the heading “{text}” is an H{level}, but the heading above it is an H{above}. Make it an H{next}.', $params),
        };
        if (!empty($issue['showTitle'])) {
            $problem .= ' ' . Craft::t('headings-checker', 'Switch off “{label}” to only show the title to screen readers.', [
                'label' => $site($issue['showTitle']),
            ]);
        }
        return $where . ': ' . $problem;
    }

    /**
     * Escapes the characters of Markdown's inline syntax (links, images, emphasis, code), so a text shows as it is in a
     * message rendered as Markdown. < and > are HTML-encoded before Craft renders it, so they can't start a link.
     */
    public static function escapeMarkdown(string $text): string
    {
        return (string)preg_replace('/([\\\\`*_\[\]()!])/', '\\\\$1', $text);
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
     * A content builder as a part of the page for HeadingOrder, and per row what it is in the control panel.
     *
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}
     */
    private function builderPart(ElementInterface $page, string $field): array
    {
        $settings = $this->settings();
        $rows = $this->rows();
        $part = ['kind' => 'builder', 'rows' => []];
        $meta = [];

        foreach ($this->entries($page->getFieldValue($field)) as $position => $block) {
            $isRow = $rows !== null && $rows->isRow($block);
            // A row's columns; any other block is a row with one column, itself
            $cells = $isRow ? $this->entries($block->getFieldValue($rows->getSettings()->cellsField)) : [1 => $block];

            $row = ['background' => $this->background($block), 'extraSpacing' => $this->extraSpacing($block), 'cells' => []];
            $rowMeta = ['block' => $block, 'position' => $position, 'isRow' => $isRow, 'cells' => []];
            foreach ($cells as $column => $cell) {
                $type = $cell->getType()->handle;
                $headings = $this->textHeadings($cell);
                $isCards = in_array($type, $settings->cardTypes, true);
                $row['cells'][] = [
                    'titles' => array_map(fn(string $title) => ['text' => $title, 'hidden' => !$this->showsTitle($cell)], $this->titles($cell)),
                    'headings' => $headings,
                    'cards' => $isCards,
                    'rowHeading' => $isCards ? fn() => $this->defaultRowHeading($cell) : null,
                ];
                $rowMeta['cells'][] = [
                    'cell' => $cell,
                    'column' => $isRow ? $column : null,
                    'headingFields' => array_column($headings, 'field'),
                ];
            }
            $part['rows'][] = $row;
            $meta[] = $rowMeta;
        }

        return [$part, $meta];
    }

    /**
     * @param array{level: int, above: int, text: string} $issue
     * @return array<string, mixed>
     */
    private function textIssue(ElementInterface $page, string $field, array $issue): array
    {
        return [
            'where' => 'field',
            'field' => $this->fieldLabel($page, $field),
            'problem' => $issue['level'] === 1 ? 'h1' : 'skip',
            'text' => $issue['text'],
            'level' => $issue['level'],
            'above' => $issue['above'],
        ];
    }

    /**
     * @param array<string, mixed> $rowMeta
     * @param array<string, mixed> $cellMeta
     * @param array{kind: string, level: int, above: int, text: string} $issue
     * @return array<string, mixed>
     */
    private function builderIssue(array $rowMeta, array $cellMeta, array $issue): array
    {
        /** @var Entry $cell */
        $cell = $cellMeta['cell'];
        $layout = $cell->getFieldLayout();
        $data = [
            'where' => $cellMeta['column'] !== null ? 'column' : 'block',
            'row' => $rowMeta['position'],
            'column' => $cellMeta['column'],
            'type' => $cell->getType()->name,
            'problem' => 'skip',
            'text' => $issue['text'],
            'level' => $issue['level'],
            'above' => $issue['above'],
        ];
        if ($issue['kind'] === HeadingOrder::ISSUE_HEADING && $issue['level'] === 1) {
            $data['problem'] = 'h1';
        } elseif ($issue['kind'] === HeadingOrder::ISSUE_CARDS || $issue['above'] === 1) {
            $data['problem'] = $issue['kind'] === HeadingOrder::ISSUE_CARDS ? 'cards' : 'noH2';
            $hasTitleField = (bool)array_filter(
                $this->settings()->titleFieldsFor($cell->getType()->handle),
                fn(string $handle) => $layout?->getFieldByHandle($handle) !== null,
            );
            $data['hint'] = match (true) {
                $hasTitleField && $cellMeta['column'] !== null => 'column',
                $hasTitleField => 'block',
                default => 'add',
            };
            $showTitle = $this->settings()->showTitleField;
            if ($showTitle !== null && $layout?->getFieldByHandle($showTitle) !== null) {
                $data['showTitle'] = $this->fieldLabel($cell, $showTitle);
            }
        }
        return $data;
    }

    /**
     * The label of a field in an element's layout (untranslated; message() translates it).
     */
    private function fieldLabel(ElementInterface $element, string $handle): string
    {
        $layout = $element->getFieldLayout();
        if ($layout?->getFieldByHandle($handle) === null) {
            return $handle;
        }
        return $layout->getField($handle)->label() ?? $handle;
    }

    /**
     * The field of a block or column of cards that gets the "no H2 above the cards" error: its title field, else its first
     * field.
     */
    private function cardsField(Entry $cell): ?string
    {
        $layout = $cell->getFieldLayout();
        foreach ($this->settings()->titleFieldsFor($cell->getType()->handle) as $handle) {
            if ($layout?->getFieldByHandle($handle) !== null) {
                return $handle;
            }
        }
        $fields = $layout?->getCustomFields() ?? [];
        return $fields ? reset($fields)->handle : null;
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
            if (!$field instanceof HtmlField && !($field instanceof Matrix && $nested)) {
                continue;
            }
            if (!$this->showsField($cell, $field->handle)) {
                continue;
            }
            if ($field instanceof Matrix) {
                foreach ($this->entries($cell->getFieldValue($field->handle)) as $entry) {
                    foreach ($this->textHeadings($entry, false) as $heading) {
                        $headings[] = ['field' => $field->handle] + $heading;
                    }
                }
                continue;
            }
            foreach (self::htmlHeadings($this->html($cell->getFieldValue($field->handle))) as $heading) {
                $headings[] = $heading + ['field' => $field->handle];
            }
        }
        return $headings;
    }

    /**
     * A rich text field's HTML as stored: not rendered, so nested entries aren't rendered and embeds aren't fetched.
     */
    private function html(mixed $value): string
    {
        if ($value instanceof HtmlFieldData) {
            return $value->getRawContent();
        }
        return is_scalar($value) || $value instanceof \Stringable ? (string)$value : '';
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
    private function layoutShowsField(ElementInterface $element, string $handle): bool
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
     * The enabled entries of a Matrix value.
     *
     * @return Entry[]
     */
    public function all(mixed $value): array
    {
        return array_values($this->entries($value));
    }

    /**
     * The enabled entries of a Matrix value by their position among all its entries (1, 2, …; disabled ones count too, as
     * the editor sees them): an array, an eager-loaded collection, or a query; a query's cached result first (the entries as
     * edited, while validating a save), as Craft's Matrix field does. A query is run eagerly: the entries of every element
     * that was fetched together with this one are loaded in one query.
     *
     * @return array<int, Entry>
     */
    private function entries(mixed $value): array
    {
        if ($value instanceof ElementQueryInterface) {
            $elements = $value->getCachedResult();
            if ($elements === null) {
                $query = (clone $value)->status(null);
                if ($this->canEagerLoad($value)) {
                    $query->eagerly('headingsChecker:' . $value->eagerLoadHandle);
                }
                $elements = $query->all();
            }
        } elseif ($value === null) {
            $elements = [];
        } else {
            $elements = is_array($value) ? $value : (method_exists($value, 'all') ? $value->all() : iterator_to_array($value));
        }

        $entries = [];
        foreach (array_values($elements) as $i => $element) {
            // Disabled entries aren't rendered
            if ($element instanceof Entry && $element->enabled && $element->getEnabledForSite()) {
                $entries[$i + 1] = $element;
            }
        }
        return $entries;
    }

    /**
     * Whether a field value's query can load the entries of every element fetched together with its owner in one query: only
     * when they're all in the owner's site, because Craft applies the owner's site to all of them.
     */
    private function canEagerLoad(ElementQueryInterface $query): bool
    {
        $source = $query->eagerLoadSourceElement ?? null;
        if (!isset($query->eagerLoadHandle) || $source === null || !isset($source->elementQueryResult)) {
            return false;
        }
        foreach ($source->elementQueryResult as $element) {
            if ($element->siteId !== $source->siteId) {
                return false;
            }
        }
        return true;
    }

    private function rowKey(Entry $row): int
    {
        return $row->id ?? spl_object_id($row);
    }

    private function settings(): Settings
    {
        return HeadingsChecker::getInstance()->getSettings();
    }
}
