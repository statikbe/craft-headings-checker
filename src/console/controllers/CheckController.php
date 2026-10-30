<?php

namespace statikbe\headingschecker\console\controllers;

use craft\console\Controller;
use craft\elements\Entry;
use craft\helpers\Console;
use statikbe\headingschecker\HeadingsChecker;
use yii\console\ExitCode;

/**
 * Lists the entries whose content builder skips a heading level: the same check as when an entry is published. Run it before
 * setting the mode to 'block' on a site with existing content, so editors aren't blocked by old pages. Without --section it
 * also rebuilds the list of the Heading issues dashboard widget.
 *
 *   php craft headings-checker/check
 *   php craft headings-checker/check --section=pages
 */
class CheckController extends Controller
{
    public $defaultAction = 'index';

    /** @var string|null Only this section (handle) */
    public ?string $section = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['section']);
    }

    public function actionIndex(): int
    {
        $plugin = HeadingsChecker::getInstance();
        // Pages in a section (not the nested entries), every site, every status
        $query = Entry::find()->section($this->section ?? '*')->site('*')->status(null)->drafts(false)->revisions(false);

        $checked = 0;
        $failing = 0;
        foreach ($query->each() as $entry) {
            /** @var Entry $entry */
            $issues = $plugin->getOutline()->issues($entry);
            if ($issues === null) {
                continue;
            }
            $checked++;
            if (!$issues) {
                continue;
            }
            $failing++;
            $this->stdout(sprintf("%s (%s, %s)\n", $entry->title, $entry->getSite()->handle, $entry->getCpEditUrl()), Console::FG_YELLOW);
            foreach ($issues as $issue) {
                $this->stdout("  - $issue\n");
            }
        }

        if ($this->section === null) {
            $plugin->getReport()->get(true);
        }

        $this->stdout(sprintf("\n%d entries checked, %d with heading issues.\n", $checked, $failing), $failing ? Console::FG_RED : Console::FG_GREEN);
        return ExitCode::OK;
    }
}
