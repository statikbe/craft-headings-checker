<?php

namespace statikbe\headingschecker\jobs;

use Craft;
use craft\queue\BaseJob;
use statikbe\headingschecker\HeadingsChecker;
use statikbe\headingschecker\services\Report;

/**
 * Checks the pages that were saved (the pending table) and updates the widget's list, after the editor's request. A batch of
 * pages per site is loaded together, so their rows and columns load a level at a time. Only one job runs at a time: pages
 * saved meanwhile are checked by this one or get a new job when it's done. A job stops well before its time limit and goes
 * on in a new one, so an import of thousands of pages never runs into it.
 */
class UpdatePagesJob extends BaseJob
{
    /** Pending pages read together (a page saved in three sites is three; they're checked per site) */
    public const BATCH = 75;

    /** @var int the id of the last pending row checked */
    public int $lastId = 0;

    public function execute($queue): void
    {
        $report = HeadingsChecker::getInstance()->getReport();
        $start = microtime(true);
        while (true) {
            $report->keepUpdateQueued();
            $rows = $report->pendingPages($this->lastId, self::BATCH);
            if (!$rows) {
                break;
            }
            $report->checkPending($rows);
            $this->lastId = (int)end($rows)['id'];
            gc_collect_cycles();
            if (microtime(true) - $start > Report::TTR / 3) {
                // Goes on in a new job, so this one ends well before its time limit
                Report::push(new self(['lastId' => $this->lastId]));
                return;
            }
        }
        $report->updateFinished();
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('headings-checker', 'Checking the headings of saved pages');
    }
}
