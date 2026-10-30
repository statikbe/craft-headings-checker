<?php

use statikbe\headingschecker\services\Outline;

it('finds the headings in order with their level', function() {
    $html = '<p>Intro</p><h2>First</h2><p>Text</p><h3 id="a">Second</h3><h4 class="x">Third</h4>';
    expect(Outline::htmlHeadings($html))->toBe([
        ['level' => 2, 'text' => 'First'],
        ['level' => 3, 'text' => 'Second'],
        ['level' => 4, 'text' => 'Third'],
    ]);
});

it('takes the text without tags, entities decoded, whitespace collapsed', function() {
    $html = "<h3>Caf&eacute; <strong>&amp;</strong>\n  <a href=\"#\">bar</a></h3>";
    expect(Outline::htmlHeadings($html))->toBe([['level' => 3, 'text' => 'Café & bar']]);
});

it('ignores headings that are not closed with the same level and other elements', function() {
    expect(Outline::htmlHeadings('<header>Not a heading</header><h2>Open</h3><p>x</p>'))->toBe([]);
});

it('returns nothing for empty HTML', function() {
    expect(Outline::htmlHeadings(''))->toBe([]);
});
