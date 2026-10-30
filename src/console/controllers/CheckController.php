<?php

namespace statikbe\headingschecker\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Entry;
use craft\helpers\Console;
use craft\helpers\Db;
use statikbe\headingschecker\HeadingsChecker;
use statikbe\headingschecker\jobs\BuildReportJob;
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
        // A full run is a build of the widget's list: one in the queue stops
        $buildId = $full ? $report->startBuild($hash) : null;

        $checked = 0;
        $failing = 0;
        $typeIds = $report->pageTypeIds();
        foreach ($typeIds ? Craft::$app->getSites()->getAllSiteIds() : [] as $siteId) {
            // Pages in a section (not the nested entries) that can have an outline, every status; a site at a time and read
            // in batches, so a batch's rows and columns load together and the pages aren't all in memory
            $query = BuildReportJob::query($typeIds)
                ->section($this->section ?? '*')
                ->siteId($siteId)
                ->orderBy(['elements.id' => SORT_ASC]);
            /** @var Entry[] $pages */
            foreach (Db::batch($query, BuildReportJob::BATCH) as $pages) {
                foreach ($report->checkPages($pages, $buildId) as $key => $issues) {
                    if ($issues === null) {
                        continue;
                    }
                    $checked++;
                    if (!$issues) {
                        continue;
                    }
                    $failing++;
                    $entry = $pages[$key];
                    $this->stdout(sprintf("%s (%s, %s)\n", $entry->title, $entry->getSite()->handle, $entry->getCpEditUrl()), Console::FG_YELLOW);
                    foreach ($issues as $issue) {
                        $this->stdout('  - ' . $outline->message($issue) . "\n");
                    }
                }
                if ($buildId !== null) {
                    $report->heartbeat();
                }
                gc_collect_cycles();
            }
        }

        if ($buildId !== null) {
            $report->finishBuild($hash, $buildId);
        }

        $this->stdout(sprintf("\n%d entries checked, %d with heading issues.\n", $checked, $failing), $failing ? Console::FG_RED : Console::FG_GREEN);
        return $failing ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
