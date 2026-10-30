<?php

namespace statikbe\headingschecker\widgets;

use Craft;
use craft\base\Widget;
use statikbe\headingschecker\HeadingsChecker;

/**
 * Dashboard widget: the pages whose content builder skips a heading level, with a link to edit each one. The list is cached
 * and updated when a page is saved or deleted.
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
        $items = array_values(HeadingsChecker::getInstance()->getReport()->get());
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
}
