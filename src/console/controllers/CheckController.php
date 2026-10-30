<?php

namespace statikbe\headingschecker\console\controllers;

use craft\console\Controller;
use craft\elements\Entry;
use craft\helpers\Console;
use statikbe\headingschecker\HeadingsChecker;
use yii\console\ExitCode;

/**
 * Lists the pages whose headings skip a level, the same check as when an entry is published.
 *
 * Run it before setting the mode to 'block' on a site with existing content, so editors aren't blocked by old pages. It also
 * updates the list of the Heading issues dashboard widget (in full without --section). Exits with 1 when a page has issues.
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

    /**
     * Lists the pages with heading issues and updates the widget's list. Exits with 1 when a page has issues.
     */
    public function actionIndex(): int
    {
        $plugin = HeadingsChecker::getInstance();
        $report = $plugin->getReport();
        $outline = $plugin->getOutline();
        $full = $this->section === null;
        $hash = $report->hash();
        if ($full) {
            $report->startBuild();
        }

        // Pages in a section (not the nested entries), every site, every status; site by site, so a batch loads together
        $query = Entry::find()
            ->section($this->section ?? '*')
            ->site('*')
            ->status(null)
            ->orderBy(['elements_sites.siteId' => SORT_ASC, 'elements.id' => SORT_ASC]);

        $checked = 0;
        $failing = 0;
        foreach ($query->each() as $entry) {
            /** @var Entry $entry */
            $issues = $report->checkPage($entry);
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
                $this->stdout('  - ' . $outline->message($issue) . "\n");
            }
        }

        if ($full) {
            $report->finishBuild($hash);
        }

        $this->stdout(sprintf("\n%d entries checked, %d with heading issues.\n", $checked, $failing), $failing ? Console::FG_RED : Console::FG_GREEN);
        return $failing ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
