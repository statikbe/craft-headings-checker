<?php

namespace statikbe\headingschecker\widgets;

use Craft;
use craft\base\Widget;
use craft\elements\Entry;
use statikbe\headingschecker\HeadingsChecker;

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
        if (!$report->ensureBuilt()) {
            return Craft::$app->getView()->renderTemplate('headings-checker/_widgets/headingIssues/body', ['building' => true]);
        }

        $outline = HeadingsChecker::getInstance()->getOutline();
        $items = [];
        foreach ($this->editable($report->items()) as [$entry, $issues]) {
            $items[] = [
                'title' => (string)$entry->title,
                'url' => (string)$entry->getCpEditUrl(),
                'site' => $entry->getSite()->getName(),
                'siteId' => (int)$entry->siteId,
                'issues' => array_map(fn(array $issue) => $outline->message($issue), $issues),
            ];
        }
        usort($items, fn(array $a, array $b) => [$a['title'], $a['siteId']] <=> [$b['title'], $b['siteId']]);

        return Craft::$app->getView()->renderTemplate('headings-checker/_widgets/headingIssues/body', [
            'building' => false,
            'items' => array_slice($items, 0, $this->limit),
            'total' => count($items),
            'multiSite' => Craft::$app->getIsMultiSite(),
        ]);
    }

    /**
     * The pages that still exist and that the user may edit: the site, and the entry (their own, or other authors' with
     * "Edit other authors' entries"). An admin sees every page.
     *
     * @param array<array{entryId: int, siteId: int, issues: array<array<string, mixed>>}> $items
     * @return array<array{0: Entry, 1: array<array<string, mixed>>}> the entry and its issues
     */
    private function editable(array $items): array
    {
        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null || !$items) {
            return [];
        }
        $entries = [];
        $query = Entry::find()
            ->id(array_values(array_unique(array_column($items, 'entryId'))))
            ->siteId(array_values(array_unique(array_column($items, 'siteId'))))
            ->status(null);
        foreach ($query->each() as $entry) {
            /** @var Entry $entry */
            $entries[$entry->id . '-' . $entry->siteId] = $entry;
        }

        $elements = Craft::$app->getElements();
        $multiSite = Craft::$app->getIsMultiSite();
        $editable = [];
        foreach ($items as $item) {
            $entry = $entries[$item['entryId'] . '-' . $item['siteId']] ?? null;
            if ($entry === null) {
                continue;
            }
            if (
                !$user->admin
                && (($multiSite && !$user->can('editSite:' . $entry->getSite()->uid)) || !$elements->canSave($entry, $user))
            ) {
                continue;
            }
            $editable[] = [$entry, $item['issues']];
        }
        return $editable;
    }
}
