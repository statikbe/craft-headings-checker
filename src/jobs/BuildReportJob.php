<?php

namespace statikbe\headingschecker\jobs;

use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;
use statikbe\headingschecker\HeadingsChecker;
use statikbe\headingschecker\helpers\BuildCursor;
use statikbe\headingschecker\services\Report;

/**
 * Builds the list of the Heading issues widget: every page (entry in a section) whose entry type has a content builder or a
 * text field, every site, every status. Site by site and in order of id, a batch at a time, so the rows and columns of a
 * batch's pages load together, and the job only carries where it is. It stops well before its time limit and goes on in a new
 * job. A newer build (the settings changed again) stops it.
 */
class BuildReportJob extends BaseJob
{
    /**
     * Pages checked together: their rows and columns load together, but a larger batch takes longer per page (measured: 25
     * pages a batch took half the time of 100)
     */
    public const BATCH = 25;

    /** @var string The settings hash the list is built for (Report::hash()) */
    public string $hash = '';

    /** @var string The id of this build (Report::ensureBuilt()) */
    public string $buildId = '';

    /** @var int The site being checked (0: the first one) */
    public int $siteId = 0;

    /** @var int The id of the last page checked in that site */
    public int $lastId = 0;

    /** @var int|null The pages to check, for the progress */
    public ?int $total = null;

    /** @var int The pages checked so far */
    public int $done = 0;

    public function execute($queue): void
    {
        $report = HeadingsChecker::getInstance()->getReport();
        if (!$report->isCurrentBuild($this->buildId)) {
            // A newer build replaced this one
            return;
        }
        $typeIds = $report->pageTypeIds();
        $this->total ??= $typeIds ? (int)self::query($typeIds)->site('*')->count() : 0;
        $cursor = new BuildCursor(Craft::$app->getSites()->getAllSiteIds(), $this->siteId, $this->lastId);
        $start = microtime(true);

        while ($typeIds && ($siteId = $cursor->siteId()) !== null) {
            $pages = self::query($typeIds)
                ->siteId($siteId)
                ->andWhere(['>', 'elements.id', $cursor->lastId()])
                ->orderBy(['elements.id' => SORT_ASC])
                ->limit(self::BATCH)
                ->all();
            $report->checkPages($pages, $this->buildId);
            $cursor->advance(array_map(fn(Entry $page) => (int)$page->id, $pages), self::BATCH);
            $this->done += count($pages);
            $this->setProgress($queue, $this->total ? min(1, $this->done / $this->total) : 1);
            $report->heartbeat();
            unset($pages);
            gc_collect_cycles();

            if ($cursor->siteId() === null) {
                break;
            }
            if (!$report->isCurrentBuild($this->buildId)) {
                return;
            }
            if (microtime(true) - $start > Report::TTR / 3) {
                // Goes on in a new job, so this one ends well before its time limit
                Report::push(new self([
                    'hash' => $this->hash,
                    'buildId' => $this->buildId,
                    'siteId' => (int)$cursor->siteId(),
                    'lastId' => $cursor->lastId(),
                    'total' => $this->total,
                    'done' => $this->done,
                ]));
                return;
            }
        }

        $report->finishBuild($this->hash, $this->buildId);
    }

    /**
     * The pages to check: entries in a section of these entry types, any status, no drafts or revisions.
     *
     * @param int[] $typeIds
     */
    public static function query(array $typeIds): \craft\elements\db\EntryQuery
    {
        return Entry::find()->section('*')->typeId($typeIds)->status(null)->drafts(false)->revisions(false);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('headings-checker', 'Finding the pages with heading issues');
    }
}
