<?php

/**
 * Every message in the headings-checker category, in PHP (Craft::t), Twig (|t) and the control panel JS (Craft.t), has a
 * translation in every language, and the JS ones are registered for the JS.
 */
function headingsCheckerMessages(string $pattern, string $glob): array
{
    $messages = [];
    foreach (glob(dirname(__DIR__, 2) . '/src/' . $glob, GLOB_BRACE) as $file) {
        preg_match_all($pattern, file_get_contents($file), $matches);
        array_push($messages, ...$matches[1]);
    }
    return array_values(array_unique($messages));
}

$php = headingsCheckerMessages("/Craft::t\\('headings-checker', '((?:[^'\\\\]|\\\\.)*)'/", '{*,*/*,*/*/*}.php');
$twig = headingsCheckerMessages("/'((?:[^'\\\\]|\\\\.)*)'\\|t\\('headings-checker'/", 'templates/{*,*/*,*/*/*}.twig');
$js = headingsCheckerMessages("/Craft\\.t\\('headings-checker', '((?:[^'\\\\]|\\\\.)*)'/", 'web/assets/cp/dist/*.js');

it('finds the messages', function() use ($php, $twig, $js) {
    expect(count($php))->toBeGreaterThan(8)
        ->and(count($twig))->toBeGreaterThan(8)
        ->and(count($js))->toBeGreaterThan(0);
});

it('translates every message', function(string $language) use ($php, $twig, $js) {
    $translations = require dirname(__DIR__, 2) . "/src/translations/{$language}/headings-checker.php";
    $messages = array_map(fn(string $message) => stripslashes($message), array_unique([...$php, ...$twig, ...$js]));
    expect(array_values(array_diff($messages, array_keys($translations))))->toBe([]);
})->with(['nl', 'fr']);

it('registers every JS message for the JS', function() use ($js) {
    $bundle = file_get_contents(dirname(__DIR__, 2) . '/src/web/assets/cp/HeadingsCheckerAsset.php');
    foreach ($js as $message) {
        expect($bundle)->toContain("'{$message}'");
    }
});
