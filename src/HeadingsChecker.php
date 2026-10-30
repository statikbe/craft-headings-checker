<?php

namespace statikbe\headingschecker;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\events\ElementEvent;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\services\Dashboard;
use craft\services\Elements;
use craft\web\twig\variables\CraftVariable;
use craft\web\View;
use statikbe\headingschecker\models\Settings;
use statikbe\headingschecker\services\Checker;
use statikbe\headingschecker\services\Outline;
use statikbe\headingschecker\services\Report;
use statikbe\headingschecker\variables\HeadingsCheckerVariable;
use statikbe\headingschecker\web\assets\cp\HeadingsCheckerAsset;
use statikbe\headingschecker\web\assets\cp\LevelBadgesAsset;
use statikbe\headingschecker\widgets\HeadingIssues;
use yii\base\Event;
use yii\queue\Queue;

/**
 * Headings Checker: the heading order of content builders, checked when an entry is published, shown in the entry sidebar
 * and listed in a dashboard widget.
 *
 * @method Settings getSettings()
 */
class HeadingsChecker extends Plugin
{
    public string $schemaVersion = '1.3.0';

    public static function config(): array
    {
        return [
            'components' => [
                'outline' => Outline::class,
                'checker' => Checker::class,
                'report' => Report::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('headingsChecker', HeadingsCheckerVariable::class);
        });

        // The check when an entry is published (settings → mode)
        Event::on(Entry::class, Element::EVENT_AFTER_VALIDATE, function(Event $event) {
            /** @var Entry $entry */
            $entry = $event->sender;
            $this->getChecker()->check($entry);
        });

        // The report of the dashboard widget follows saved and deleted pages
        Event::on(Entry::class, Element::EVENT_AFTER_SAVE, function(ModelEvent $event) {
            /** @var Entry $entry */
            $entry = $event->sender;
            $this->getReport()->refresh($entry);
        });
        Event::on(Entry::class, Element::EVENT_AFTER_DELETE, function(Event $event) {
            /** @var Entry $entry */
            $entry = $event->sender;
            if ($entry->getSection() !== null) {
                $this->getReport()->forget($entry);
            }
        });
        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_FOR_SITE, function(ElementEvent $event) {
            $entry = $event->element;
            if ($entry instanceof Entry && $entry->getSection() !== null) {
                $this->getReport()->forget($entry, $entry->siteId);
            }
        });
        Event::on(Entry::class, Element::EVENT_AFTER_RESTORE, function(Event $event) {
            /** @var Entry $entry */
            $entry = $event->sender;
            $this->getReport()->refreshAllSites($entry);
        });

        // A queue job (an import) is done: the pages it saved are queued, without waiting for the worker or the web request
        // that runs the queue to end
        Event::on(Queue::class, Queue::EVENT_AFTER_EXEC, fn() => $this->getReport()->flushPending());
        Event::on(Queue::class, Queue::EVENT_AFTER_ERROR, fn() => $this->getReport()->flushPending());

        Event::on(Dashboard::class, Dashboard::EVENT_REGISTER_WIDGET_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = HeadingIssues::class;
        });

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            Event::on(View::class, View::EVENT_REGISTER_CP_TEMPLATE_ROOTS, function(RegisterTemplateRootsEvent $event) {
                $event->roots['headings-checker'] = __DIR__ . '/templates';
            });

            // The Heading outline panel in an entry's sidebar, under the notes
            Event::on(Entry::class, Element::EVENT_DEFINE_SIDEBAR_HTML, function(DefineHtmlEvent $event) {
                /** @var Entry $entry */
                $entry = $event->sender;
                $event->html .= $this->sidebarHtml($entry);
            });

            // The level badges in rich text fields
            if ($this->getSettings()->levelBadges) {
                Event::on(View::class, View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE, function() {
                    Craft::$app->getView()->registerAssetBundle(LevelBadgesAsset::class);
                });
            }
        }
    }

    public function getOutline(): Outline
    {
        return $this->get('outline');
    }

    public function getChecker(): Checker
    {
        return $this->get('checker');
    }

    public function getReport(): Report
    {
        return $this->get('report');
    }

    /**
     * The Heading outline panel: every heading of the page in order, collapsible (settings → outline). The list is loaded
     * after the page (outline.js), so the panel never slows the edit page down. Only for entries whose layout has a content
     * builder or a text field.
     */
    public function sidebarHtml(Entry $entry): string
    {
        if (!$this->getSettings()->outline || $entry->getIsRevision() || $entry->getSection() === null) {
            return '';
        }
        $outline = $this->getOutline();
        if (!$outline->builderFields($entry) && !$outline->textFields($entry)) {
            return '';
        }
        $view = Craft::$app->getView();
        $view->registerAssetBundle(HeadingsCheckerAsset::class);
        return $view->renderTemplate('headings-checker/_outline/panel', [
            'entry' => $entry,
        ], View::TEMPLATE_MODE_CP);
    }

    /**
     * The list of the Heading outline panel, also to refresh it after an autosave.
     */
    public function outlineListHtml(Entry $entry): string
    {
        return Craft::$app->getView()->renderTemplate('headings-checker/_outline/list', [
            'tree' => $this->getOutline()->pageTree($entry) ?? [],
        ], View::TEMPLATE_MODE_CP);
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }
}
