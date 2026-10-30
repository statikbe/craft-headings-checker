<?php

namespace statikbe\headingschecker\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\QueryBatcher;
use craft\elements\Entry;
use craft\queue\BaseBatchedJob;
use statikbe\headingschecker\HeadingsChecker;

/**
 * Builds the list of the Heading issues widget: every page (entry in a section), every site, every status. Site by site, so
 * the rows and columns of a batch's pages are loaded together.
 */
class BuildReportJob extends BaseBatchedJob
{
    /** @var string The settings hash the list is built for (Report::hash()) */
    public string $hash = '';

    protected function loadData(): Batchable
    {
        return new QueryBatcher(Entry::find()
            ->section('*')
            ->site('*')
            ->status(null)
            ->orderBy(['elements_sites.siteId' => SORT_ASC, 'elements.id' => SORT_ASC]));
    }

    protected function before(): void
    {
        HeadingsChecker::getInstance()->getReport()->startBuild();
    }

    protected function processItem(mixed $item): void
    {
        /** @var Entry $item */
        try {
            HeadingsChecker::getInstance()->getReport()->checkPage($item);
        } catch (\Throwable $e) {
            // One page that can't be read doesn't stop the list
            Craft::warning("Couldn't check the headings of entry $item->id in site $item->siteId: {$e->getMessage()}", __METHOD__);
        }
    }

    protected function afterBatch(): void
    {
        HeadingsChecker::getInstance()->getReport()->heartbeat();
    }

    protected function after(): void
    {
        HeadingsChecker::getInstance()->getReport()->finishBuild($this->hash);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('headings-checker', 'Finding the pages with heading issues');
    }
}
