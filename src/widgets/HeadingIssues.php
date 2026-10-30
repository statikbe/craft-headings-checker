<?php

namespace statikbe\headingschecker\widgets;

use Craft;
use craft\base\Widget;
use craft\db\Query;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use statikbe\headingschecker\HeadingsChecker;
use statikbe\headingschecker\services\Report;

/**
 * Dashboard widget: the pages whose content builder skips a heading level, with a link to edit each one. The list is kept
 * in a table (Report) and updated when a page is saved or deleted. Each user only sees the pages they may edit.
 */
class HeadingIssues extends Widget
{
    /** @var int Pages shown, the rest is counted */
    public int $limit = 25;

    public static function displayName(): string
    {
        return Craft::t('headings-checker', 'Heading issues');
    }

    public static function icon(): ?string
    {
        return 'heading';
    }

    public function getTitle(): ?string
    {
        return Craft::t('headings-checker', 'Heading issues');
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['limit'], 'integer', 'min' => 1];
        return $rules;
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('headings-checker/_widgets/headingIssues/settings', ['widget' => $this]);
    }

    public function getBodyHtml(): ?string
    {
        $report = HeadingsChecker::getInstance()->getReport();
        $state = $report->ensureBuilt();
        $view = Craft::$app->getView();
        if ($state === 'building') {
            return $view->renderTemplate('headings-checker/_widgets/headingIssues/body', ['building' => true]);
        }

        // Sorted, counted and cut to the limit by the database: only the pages shown are loaded
        $query = $this->query();
        $total = $query ? (int)$query->count() : 0;
        $entries = $query && $total ? $query->limit($this->limit)->all() : [];

        $outline = HeadingsChecker::getInstance()->getOutline();
        $issues = $report->issuesOf(array_map(fn(Entry $entry) => [(int)$entry->id, (int)$entry->siteId], $entries));
        $items = [];
        foreach ($entries as $entry) {
            /** @var Entry $entry */
            $items[] = [
                'title' => (string)$entry->title,
                'url' => (string)$entry->getCpEditUrl(),
                'site' => $entry->getSite()->getName(),
                'siteId' => (int)$entry->siteId,
                'issues' => array_map(fn(array $issue) => $outline->message($issue), $issues[$entry->id . '-' . $entry->siteId] ?? []),
            ];
        }

        return $view->renderTemplate('headings-checker/_widgets/headingIssues/body', [
            'building' => false,
            'rebuilding' => $state === 'rebuilding',
            'items' => $items,
            'total' => $total,
            'multiSite' => Craft::$app->getIsMultiSite(),
        ]);
    }

    /**
     * The pages in the list that still exist and that the user may edit, by title: in a site they may edit, and an entry
     * they may save (their own, or other authors' with "Edit other authors' entries"). An admin sees every page. Null when
     * the user may edit none.
     */
    private function query(): ?EntryQuery
    {
        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null) {
            return null;
        }
        $query = Entry::find()
            ->section('*')
            ->site('*')
            ->status(null)
            ->drafts(false)
            ->revisions(false)
            ->andWhere(['exists', (new Query())
                ->from(['hc' => Report::TABLE])
                ->where('[[hc.entryId]] = [[elements.id]] AND [[hc.siteId]] = [[elements_sites.siteId]]'), ])
            ->orderBy(['elements_sites.title' => SORT_ASC, 'elements_sites.siteId' => SORT_ASC, 'elements.id' => SORT_ASC]);
        if (!$user->admin) {
            $siteIds = Craft::$app->getSites()->getEditableSiteIds();
            if (!$siteIds) {
                return null;
            }
            $query->siteId($siteIds)->savable();
        }
        return $query;
    }
}
