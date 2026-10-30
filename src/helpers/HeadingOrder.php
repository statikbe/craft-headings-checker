<?php

namespace statikbe\headingschecker\helpers;

/**
 * The heading order rules over a page as plain data, without Craft: one pass over the page's text fields and content builders
 * in order, so a heading in a text field counts for the builder below it, and a second builder goes on where the first stopped.
 *
 * A page is a list of parts:
 * - ['kind' => 'text', 'headings' => [['level' => 2, 'text' => '…'], …]]: a rich text field of the page, under the h1;
 * - ['kind' => 'builder', 'rows' => [row, …]]: a content builder. A row: ['background' => string, 'extraSpacing' => bool,
 *   'cells' => [cell, …]]; without Trinity every block is a row with one cell. A cell: ['titles' => [['text' => '…',
 *   'hidden' => bool], …], 'headings' => [['level' => 3, 'text' => '…'], …], 'cards' => bool, 'rowHeading' => the hidden h2's
 *   text for a row of these cards: a string, null, or a function returning one (only called when it's needed)].
 *
 * The rules:
 * - a heading may go at most one level deeper than the heading above it, and only the page has an h1;
 * - cards are h3: a row whose cards come before any h2 of its own (a title, or an h2 in its text) gets a hidden h2 when no h2
 *   came before it on the page, or when it starts a new part of the page (the first row of a builder, another background than
 *   the row above, or more spacing above than "close");
 * - cards that still have no h2 above them (no hidden h2 configured) are an issue too.
 */
class HeadingOrder
{
    public const ISSUE_HEADING = 'heading';
    public const ISSUE_CARDS = 'cards';

    /**
     * @param array<array<string, mixed>> $parts
     * @return array{
     *     tree: array<array{level: int, text: string, kind: string, hidden: bool, issue: bool, part: int, row: int|null, cell: int|null}>,
     *     issues: array<array{kind: string, part: int, row: int|null, cell: int|null, heading: int|null, level: int, above: int, text: string}>,
     *     rowHeadings: array<int, array<int, string>>,
     * }
     */
    public static function walk(array $parts): array
    {
        $result = ['tree' => [], 'issues' => [], 'rowHeadings' => []];
        $level = 1;
        $seenH2 = false;

        foreach ($parts as $p => $part) {
            if (($part['kind'] ?? null) === 'text') {
                foreach ($part['headings'] as $h => $heading) {
                    $issue = self::skips($heading['level'], $level);
                    if ($issue) {
                        $result['issues'][] = self::issue(self::ISSUE_HEADING, $p, null, null, $h, $heading['level'], $level, $heading['text']);
                    }
                    $result['tree'][] = self::node($heading['level'], $heading['text'], 'text', false, $issue, $p, null, null);
                    $level = $heading['level'];
                    $seenH2 = $seenH2 || $level === 2;
                }
                continue;
            }

            $previousBackground = null;
            foreach ($part['rows'] ?? [] as $r => $row) {
                $background = (string)($row['background'] ?? '');
                $newPart = $r === array_key_first($part['rows']) || $background !== $previousBackground || !empty($row['extraSpacing']);
                $previousBackground = $background;
                $cells = $row['cells'] ?? [];

                // The hidden h2 for a row whose cards come before any h2 of its own, in source order
                $cardCell = null;
                foreach ($cells as $c => $cell) {
                    if (!empty($cell['titles']) || in_array(2, array_column($cell['headings'] ?? [], 'level'), true)) {
                        break;
                    }
                    if (!empty($cell['cards'])) {
                        $cardCell = $c;
                        break;
                    }
                }
                if ($cardCell !== null && (!$seenH2 || $newPart)) {
                    $text = $cells[$cardCell]['rowHeading'] ?? null;
                    $text = is_callable($text) ? $text() : $text;
                    if (is_string($text) && $text !== '') {
                        $result['rowHeadings'][$p][$r] = $text;
                        $result['tree'][] = self::node(2, $text, 'row', true, false, $p, $r, null);
                        $level = 2;
                        $seenH2 = true;
                    }
                }

                foreach ($cells as $c => $cell) {
                    foreach ($cell['titles'] ?? [] as $title) {
                        $level = 2;
                        $seenH2 = true;
                        $result['tree'][] = self::node(2, $title['text'], 'title', (bool)($title['hidden'] ?? false), false, $p, $r, $c);
                    }
                    foreach ($cell['headings'] ?? [] as $h => $heading) {
                        $issue = self::skips($heading['level'], $level);
                        if ($issue) {
                            $result['issues'][] = self::issue(self::ISSUE_HEADING, $p, $r, $c, $h, $heading['level'], $level, $heading['text']);
                        }
                        $result['tree'][] = self::node($heading['level'], $heading['text'], 'text', false, $issue, $p, $r, $c);
                        $level = $heading['level'];
                        $seenH2 = $seenH2 || $level === 2;
                    }
                    if (!empty($cell['cards'])) {
                        // h3 cards right under the h1: no title before them and no hidden h2 configured
                        $issue = $level < 2;
                        if ($issue) {
                            $result['issues'][] = self::issue(self::ISSUE_CARDS, $p, $r, $c, null, 3, $level, '');
                        } else {
                            $level = 3;
                        }
                        $result['tree'][] = self::node(3, '', 'cards', false, $issue, $p, $r, $c);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Whether a heading skips a level after the heading above it, or is a second h1.
     */
    public static function skips(int $level, int $above): bool
    {
        return $level === 1 || $level > $above + 1;
    }

    /**
     * @return array{level: int, text: string, kind: string, hidden: bool, issue: bool, part: int, row: int|null, cell: int|null}
     */
    private static function node(int $level, string $text, string $kind, bool $hidden, bool $issue, int $part, ?int $row, ?int $cell): array
    {
        return compact('level', 'text', 'kind', 'hidden', 'issue', 'part', 'row', 'cell');
    }

    /**
     * @return array{kind: string, part: int, row: int|null, cell: int|null, heading: int|null, level: int, above: int, text: string}
     */
    private static function issue(string $kind, int $part, ?int $row, ?int $cell, ?int $heading, int $level, int $above, string $text): array
    {
        return compact('kind', 'part', 'row', 'cell', 'heading', 'level', 'above', 'text');
    }
}
