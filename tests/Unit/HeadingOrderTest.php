<?php

use statikbe\headingschecker\helpers\HeadingOrder;
use statikbe\headingschecker\services\Outline;

/** A cell (block or column): titles, rich text headings [level, text], cards, the hidden h2 for a row of these cards */
function hcCell(array $titles = [], array $headings = [], bool $cards = false, mixed $rowHeading = null): array
{
    return [
        'titles' => array_map(fn($title) => is_array($title) ? $title : ['text' => $title, 'hidden' => false], $titles),
        'headings' => array_map(fn(array $h) => ['level' => $h[0], 'text' => $h[1]], $headings),
        'cards' => $cards,
        'rowHeading' => $rowHeading,
    ];
}

function hcRow(array $cells, string $background = '', bool $extraSpacing = false): array
{
    return ['background' => $background, 'extraSpacing' => $extraSpacing, 'cells' => $cells];
}

function hcBuilder(array $rows): array
{
    return ['kind' => 'builder', 'rows' => $rows];
}

function hcText(array $headings): array
{
    return ['kind' => 'text', 'headings' => array_map(fn(array $h) => ['level' => $h[0], 'text' => $h[1]], $headings)];
}

/** The tree as "H2 text" lines, issues marked with ! */
function hcTree(array $result): array
{
    return array_map(fn(array $node) => ($node['issue'] ? '!' : '') . 'H' . $node['level'] . ' ' . $node['kind'] . ($node['text'] !== '' ? ' ' . $node['text'] : ''), $result['tree']);
}

it('accepts a heading one level below the one above', function() {
    $result = HeadingOrder::walk([hcBuilder([hcRow([hcCell(['Title'], [[3, 'Sub'], [4, 'Deeper'], [2, 'Back up']])])])]);
    expect($result['issues'])->toBe([])
        ->and(hcTree($result))->toBe(['H2 title Title', 'H3 text Sub', 'H4 text Deeper', 'H2 text Back up']);
});

it('flags a heading that skips a level, and a second h1', function() {
    $result = HeadingOrder::walk([hcBuilder([hcRow([hcCell(['Title'], [[4, 'Too deep'], [1, 'Another h1']])])])]);
    expect(array_column($result['issues'], 'text'))->toBe(['Too deep', 'Another h1'])
        ->and($result['issues'][0])->toMatchArray(['kind' => 'heading', 'part' => 0, 'row' => 0, 'cell' => 0, 'heading' => 0, 'level' => 4, 'above' => 2]);
});

it('flags an h3 right under the h1', function() {
    $result = HeadingOrder::walk([hcBuilder([hcRow([hcCell([], [[3, 'No title']])])])]);
    expect($result['issues'][0])->toMatchArray(['level' => 3, 'above' => 1]);
});

it('carries the level from the text fields into the content builder', function() {
    $result = HeadingOrder::walk([
        hcText([[2, 'Practical info']]),
        hcBuilder([hcRow([hcCell([], [[3, 'Opening hours']])])]),
    ]);
    expect($result['issues'])->toBe([])
        ->and(hcTree($result))->toBe(['H2 text Practical info', 'H3 text Opening hours']);
});

it('checks a text field right under the h1', function() {
    $result = HeadingOrder::walk([hcText([[3, 'Too deep']])]);
    expect($result['issues'][0])->toMatchArray(['part' => 0, 'row' => null, 'heading' => 0, 'level' => 3, 'above' => 1]);
});

it('goes on in a second content builder where the first stopped', function() {
    $result = HeadingOrder::walk([
        hcBuilder([hcRow([hcCell(['Intro'])])]),
        hcBuilder([hcRow([hcCell([], [[3, 'Detail']])])]),
    ]);
    expect($result['issues'])->toBe([]);
});

it('gives a row of cards without an h2 of its own a hidden h2', function() {
    $result = HeadingOrder::walk([hcBuilder([hcRow([hcCell([], [], true, 'Related news')])])]);
    expect($result['rowHeadings'])->toBe([0 => [0 => 'Related news']])
        ->and($result['issues'])->toBe([])
        ->and(hcTree($result))->toBe(['H2 row Related news', 'H3 cards']);
});

it('only asks for the hidden h2 when it is needed', function() {
    $called = false;
    $result = HeadingOrder::walk([hcBuilder([hcRow([hcCell(['Our team']), hcCell([], [], true, function() use (&$called) {
        $called = true;
        return 'Team';
    })])])]);
    expect($called)->toBeFalse()
        ->and($result['rowHeadings'])->toBe([]);
});

it('gives no hidden h2 to cards in the same part of the page after an h2', function() {
    $result = HeadingOrder::walk([hcBuilder([
        hcRow([hcCell(['Title'])], 'white'),
        hcRow([hcCell([], [], true, 'Cards')], 'white'),
    ])]);
    expect($result['rowHeadings'])->toBe([]);
});

it('gives a hidden h2 to cards that start a new part of the page', function(string $background, bool $extraSpacing) {
    $result = HeadingOrder::walk([hcBuilder([
        hcRow([hcCell(['Title'])], 'white'),
        hcRow([hcCell([], [], true, 'Cards')], $background, $extraSpacing),
    ])]);
    expect($result['rowHeadings'])->toBe([0 => [1 => 'Cards']]);
})->with([
    'another background' => ['grey', false],
    'more spacing above' => ['white', true],
]);

it('gives the first row of a content builder a hidden h2 above cards, also after a text field with an h2', function() {
    $result = HeadingOrder::walk([
        hcText([[2, 'Intro']]),
        hcBuilder([hcRow([hcCell([], [], true, 'Cards')])]),
    ]);
    expect($result['rowHeadings'])->toBe([1 => [0 => 'Cards']]);
});

it('flags cards right under the h1 when no hidden h2 is configured', function() {
    $result = HeadingOrder::walk([hcBuilder([hcRow([hcCell([], [], true, null)]), hcRow([hcCell([], [], true, fn() => null)])])]);
    expect($result['issues'])->toHaveCount(2)
        ->and($result['issues'][0])->toMatchArray(['kind' => 'cards', 'row' => 0, 'cell' => 0, 'heading' => null, 'level' => 3, 'above' => 1])
        ->and(hcTree($result))->toBe(['!H3 cards', '!H3 cards']);
});

it('reads a title before the cards in the same row as their h2', function() {
    $result = HeadingOrder::walk([hcBuilder([hcRow([hcCell(['Partners']), hcCell([], [], true, 'Cards')])])]);
    expect($result['rowHeadings'])->toBe([])
        ->and($result['issues'])->toBe([]);
});

it('counts a hidden title as an h2', function() {
    $result = HeadingOrder::walk([hcBuilder([hcRow([hcCell([['text' => 'For screen readers', 'hidden' => true]], [[3, 'Sub']])])])]);
    expect($result['issues'])->toBe([])
        ->and($result['tree'][0]['hidden'])->toBeTrue();
});

it('lets a heading after cards go one level below them', function() {
    $result = HeadingOrder::walk([hcBuilder([hcRow([hcCell(['Title'], [], true), hcCell([], [[4, 'Under the cards']])])])]);
    expect($result['issues'])->toBe([]);
});

it('escapes Markdown in texts', function() {
    expect(Outline::escapeMarkdown('Details [open](javascript:alert(1)) ![](https://x.test/p.png) *bold* `code`'))
        ->toBe('Details \[open\]\(javascript:alert\(1\)\) \!\[\]\(https://x.test/p.png\) \*bold\* \`code\`');
});
