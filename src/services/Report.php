<?php

namespace statikbe\headingschecker\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use statikbe\headingschecker\HeadingsChecker;

/**
 * The pages with heading issues, for the Heading issues dashboard widget. Kept in the cache, updated when a page is saved or
 * deleted, and built in full when the cache is empty (or by `craft headings-checker/check`).
 */
class Report extends Component
{
    /** @var array<string, array{0: int, 1: int}> pages to update at the end of the request */
    private array $pending = [];

    /**
     * [entryId-siteId => [entryId, siteId, title, url, issues]] of the pages in a section, every site, also disabled ones.
     *
     * @return array<string, array{entryId: int, siteId: int, title: string, url: string, issues: string[]}>
     */
    public function get(bool $rebuild = false): array
    {
        $cache = Craft::$app->getCache();
        $report = $rebuild ? false : $cache->get($this->cacheKey());
        if (is_array($report)) {
            return $report;
        }

        $report = [];
        $outline = HeadingsChecker::getInstance()->getOutline();
        $query = Entry::find()->section('*')->site('*')->status(null)->drafts(false)->revisions(false);
        foreach ($query->each() as $entry) {
            /** @var Entry $entry */
            if ($issues = $outline->issues($entry)) {
                $report[$this->key($entry)] = $this->item($entry, $issues);
            }
        }
        $cache->set($this->cacheKey(), $report, 0);
        return $report;
    }

    /**
     * Updates the report for a saved entry: a page, or the page a saved row, block or column is on. Only when the report is
     * in the cache (otherwise it's built in full when it's needed). Once per page, at the end of the request, so saving a page
     * with all its blocks checks it once, as saved.
     */
    public function refresh(Entry $entry): void
    {
        if ($entry->getIsDraft() || $entry->getIsRevision() || !$entry->getIsCanonical()) {
            return;
        }
        // A nested entry: the page it's on
        $page = $entry;
        for ($i = 0; $page->getSection() === null && $i < 5; $i++) {
            $owner = $page->getPrimaryOwner();
            if (!$owner instanceof Entry || $owner->getIsDraft() || $owner->getIsRevision()) {
                return;
            }
            $page = $owner;
        }
        if ($page->getSection() === null) {
            return;
        }

        if (!$this->pending) {
            Craft::$app->onAfterRequest(fn() => $this->refreshPending());
        }
        $this->pending[$this->key($page)] = [(int)$page->id, (int)$page->siteId];
    }

    /**
     * Removes a deleted page from the report.
     */
    public function forget(Entry $entry): void
    {
        $cache = Craft::$app->getCache();
        $report = $cache->get($this->cacheKey());
        if (!is_array($report) || !isset($report[$this->key($entry)])) {
            return;
        }
        // Deleting removes the entry in every site
        $report = array_filter($report, fn(array $item) => $item['entryId'] !== $entry->id);
        $cache->set($this->cacheKey(), $report, 0);
    }

    private function refreshPending(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        $cache = Craft::$app->getCache();
        $report = $cache->get($this->cacheKey());
        if (!is_array($report)) {
            return;
        }
        $outline = HeadingsChecker::getInstance()->getOutline();
        foreach ($pending as $key => [$id, $siteId]) {
            $page = Entry::find()->id($id)->siteId($siteId)->status(null)->one();
            $issues = $page ? $outline->issues($page) : null;
            if ($page && $issues) {
                $report[$key] = $this->item($page, $issues);
            } else {
                unset($report[$key]);
            }
        }
        $cache->set($this->cacheKey(), $report, 0);
    }

    /**
     * The report's cache key: changes with the outline rules (Outline::VERSION) and with the settings that decide what's an
     * issue, so a changed config builds a new report.
     */
    private function cacheKey(): string
    {
        $settings = HeadingsChecker::getInstance()->getSettings();
        $hash = md5((string)json_encode([
            $settings->fields,
            $settings->titleFields,
            $settings->cardTypes,
            array_keys($settings->rowHeadings),
            $settings->showTitleField,
            $settings->pageTitle,
        ]));
        return 'headings-checker.report.v' . Outline::VERSION . '.' . $hash;
    }

    private function key(Entry $entry): string
    {
        return $entry->id . '-' . $entry->siteId;
    }

    /**
     * @param string[] $issues
     * @return array{entryId: int, siteId: int, title: string, url: string, issues: string[]}
     */
    private function item(Entry $entry, array $issues): array
    {
        return [
            'entryId' => (int)$entry->id,
            'siteId' => (int)$entry->siteId,
            'title' => (string)$entry->title,
            'url' => (string)$entry->getCpEditUrl(),
            'issues' => $issues,
        ];
    }
}
