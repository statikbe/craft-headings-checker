<?php

namespace statikbe\headingschecker\widgets;

use Craft;
use craft\base\Widget;
use craft\elements\Entry;
use statikbe\headingschecker\HeadingsChecker;

/**
 * Dashboard widget: the pages whose content builder skips a heading level, with a link to edit each one. The list is cached
 * and updated when a page is saved or deleted. Each user only sees the pages they may edit.
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
        $sites = Craft::$app->getSites();
        $items = $this->editable(array_values(HeadingsChecker::getInstance()->getReport()->get()));
        usort($items, fn(array $a, array $b) => [$a['title'], $a['siteId']] <=> [$b['title'], $b['siteId']]);
        foreach ($items as &$item) {
            $item['site'] = $sites->getSiteById($item['siteId'], true)?->getName() ?? '';
        }
        unset($item);

        return Craft::$app->getView()->renderTemplate('headings-checker/_widgets/headingIssues/body', [
            'items' => array_slice($items, 0, $this->limit),
            'total' => count($items),
            'multiSite' => Craft::$app->getIsMultiSite(),
        ]);
    }

    /**
     * Only the pages that still exist and that the user may edit: the site, and the entry (their own, or other authors' with
     * "Edit other authors' entries"). An admin sees every page.
     *
     * @param array<array{entryId: int, siteId: int, title: string, url: string, issues: string[]}> $items
     * @return array<array{entryId: int, siteId: int, title: string, url: string, issues: string[]}>
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
        return array_values(array_filter($items, function(array $item) use ($entries, $elements, $user, $multiSite) {
            $entry = $entries[$item['entryId'] . '-' . $item['siteId']] ?? null;
            if ($entry === null) {
                return false;
            }
            if ($user->admin) {
                return true;
            }
            if ($multiSite && !$user->can('editSite:' . $entry->getSite()->uid)) {
                return false;
            }
            return $elements->canSave($entry, $user);
        }));
    }
}
