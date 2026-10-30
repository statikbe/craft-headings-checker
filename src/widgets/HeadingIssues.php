<?php

namespace statikbe\headingschecker\widgets;

use Craft;
use craft\base\Widget;
use craft\elements\Entry;
use statikbe\headingschecker\HeadingsChecker;

/**
 * Dashboard widget: the pages whose content builder skips a heading level, with a link to edit each one. The list is cached
 * and updated when a page is saved or deleted. Each user only sees the pages in sections they may edit.
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
     * Only the pages in sections the user may edit (an admin sees every page).
     *
     * @param array<array{entryId: int, siteId: int, title: string, url: string, issues: string[]}> $items
     * @return array<array{entryId: int, siteId: int, title: string, url: string, issues: string[]}>
     */
    private function editable(array $items): array
    {
        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null || $user->admin || !$items) {
            return $items;
        }
        $sections = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if ($user->can("saveEntries:$section->uid") || $user->can("savePeerEntries:$section->uid")) {
                $sections[] = $section->id;
            }
        }
        if (!$sections) {
            return [];
        }
        $ids = array_flip(Entry::find()
            ->id(array_values(array_unique(array_column($items, 'entryId'))))
            ->sectionId($sections)
            ->site('*')
            ->unique()
            ->status(null)
            ->ids());
        return array_values(array_filter($items, fn(array $item) => isset($ids[$item['entryId']])));
    }
}
