<?php

namespace statikbe\headingschecker\variables;

use craft\base\ElementInterface;
use craft\elements\Entry;
use statikbe\headingschecker\HeadingsChecker;

/**
 * craft.headingsChecker in templates.
 */
class HeadingsCheckerVariable
{
    /**
     * The hidden h2 above a row or block of cards that has no h2 of its own before the cards, or null. Render it for screen
     * readers only:
     *
     * ```twig
     * {% set rowHeading = craft.headingsChecker.rowHeading(entry, block) %}
     * {% if rowHeading %}<h2 class="sr-only">{{ rowHeading }}</h2>{% endif %}
     * ```
     */
    public function rowHeading(ElementInterface $page, Entry $row, ?string $field = null): ?string
    {
        return HeadingsChecker::getInstance()->getOutline()->rowHeading($page, $row, $field);
    }

    /**
     * Whether a block or column shows its title (settings → showTitleField); a hidden title is for screen readers only.
     */
    public function showsTitle(Entry $block): bool
    {
        return HeadingsChecker::getInstance()->getOutline()->showsTitle($block);
    }

    /**
     * Every heading of a page in order (level, text, kind, hidden, issue), or null without a content builder.
     *
     * @return array<array{level: int, text: string, kind: string, hidden: bool, issue: bool, row: int}>|null
     */
    public function outline(Entry $entry): ?array
    {
        return HeadingsChecker::getInstance()->getOutline()->pageTree($entry);
    }
}
