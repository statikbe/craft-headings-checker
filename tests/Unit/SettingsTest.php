<?php

use statikbe\headingschecker\models\Settings;

it('uses one list of title fields for every entry type', function() {
    $settings = new Settings(['titleFields' => ['blockTitle']]);
    expect($settings->titleFieldsFor('textImage'))->toBe(['blockTitle']);
});

it('uses the title fields of the entry type, else the ones for every type', function() {
    $settings = new Settings(['titleFields' => ['*' => ['blockTitle'], 'textTwoColumns' => ['titleColumn1', 'titleColumn2']]]);
    expect($settings->titleFieldsFor('textTwoColumns'))->toBe(['titleColumn1', 'titleColumn2'])
        ->and($settings->titleFieldsFor('textImage'))->toBe(['blockTitle']);
});

it('has no title fields for an entry type without its own and without a default', function() {
    $settings = new Settings(['titleFields' => ['textImage' => ['blockTitle']]]);
    expect($settings->titleFieldsFor('quote'))->toBe([]);
});

it('takes one or more page title paths', function() {
    expect((new Settings(['pageTitle' => 'hero.heading']))->pageTitlePaths())->toBe(['hero.heading'])
        ->and((new Settings(['pageTitle' => ['hero.heading', 'pageTitle']]))->pageTitlePaths())->toBe(['hero.heading', 'pageTitle'])
        ->and((new Settings())->pageTitlePaths())->toBe([]);
});

it('uses the text fields of the entry type, else the ones for every type', function() {
    $settings = new Settings(['textFields' => ['*' => ['intro'], 'registration' => ['description']]]);
    expect($settings->textFieldsFor('registration'))->toBe(['description'])
        ->and($settings->textFieldsFor('page'))->toBe(['intro'])
        ->and((new Settings())->textFieldsFor('page'))->toBe([]);
});
