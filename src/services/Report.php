<?php

namespace statikbe\headingschecker\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\Queue;
use statikbe\headingschecker\HeadingsChecker;
use statikbe\headingschecker\jobs\BuildReportJob;

/**
 * The pages with heading issues, for the Heading issues dashboard widget: one row per page and site in a table, updated when a
 * page is saved, deleted or restored. The full list is built by a queue job (BuildReportJob) or by `craft headings-checker/check`,
 * never in a web request: when the settings change (hash()), or when the plugin was just installed.
 *
 * The issues are stored as data (Outline::pageOutline() → issues) and turned into messages when they're shown, in the
 * language of the user who reads them.
 */
class Report extends Component
{
    public const TABLE = '{{%headingschecker_pages}}';
    public const STATE_TABLE = '{{%headingschecker_state}}';

    /** Seconds after which a build that stopped reporting progress is queued again */
    private const STALLED = 900;

    /** @var array<string, array{0: int, 1: int}> pages to update at the end of the request */
    private array $pending = [];

    private ?string $hash = null;

    /**
     * Whether the list was built for the current settings. Queues a build when it wasn't and none is running.
     */
    public function ensureBuilt(): bool
    {
        $hash = $this->hash();
        if ($this->state('builtHash') === $hash) {
            return true;
        }
        $mutex = Craft::$app->getMutex();
        if ($mutex->acquire('headings-checker.report.queue', 5)) {
            try {
                $heartbeat = (int)$this->state('heartbeat');
                if ($this->state('queuedHash') !== $hash || $heartbeat < time() - self::STALLED) {
                    $this->setState('queuedHash', $hash);
                    $this->setState('heartbeat', (string)time());
                    Queue::push(new BuildReportJob(['hash' => $hash]));
                }
            } finally {
                $mutex->release('headings-checker.report.queue');
            }
        }
        return false;
    }

    /**
     * The pages with issues: [['entryId' => …, 'siteId' => …, 'issues' => [issue data, …]], …].
     *
     * @return array<array{entryId: int, siteId: int, issues: array<array<string, mixed>>}>
     */
    public function items(): array
    {
        $items = [];
        foreach ((new Query())->select(['entryId', 'siteId', 'issues'])->from(self::TABLE)->all() as $row) {
            $items[] = [
                'entryId' => (int)$row['entryId'],
                'siteId' => (int)$row['siteId'],
                'issues' => Json::decodeIfJson((string)$row['issues']) ?: [],
            ];
        }
        return $items;
    }

    /**
     * Checks a page and stores the result: a row when it has issues, none when it hasn't.
     *
     * @return array<array<string, mixed>>|null the issues, null when it has no text fields and no content builder
     */
    public function checkPage(Entry $page): ?array
    {
        $outline = HeadingsChecker::getInstance()->getOutline()->pageOutline($page, true);
        $issues = $outline['applies'] ? $outline['issues'] : [];
        $this->store((int)$page->id, (int)$page->siteId, $issues);
        return $outline['applies'] ? $issues : null;
    }

    /**
     * Before a full build: the rows of the previous list are removed.
     */
    public function startBuild(): void
    {
        Db::delete(self::TABLE);
        $this->heartbeat();
    }

    /**
     * Keeps a running build from being queued again.
     */
    public function heartbeat(): void
    {
        $this->setState('heartbeat', (string)time());
    }

    /**
     * After a full build for these settings: the list is ready, unless the settings changed in the meantime.
     */
    public function finishBuild(string $hash): void
    {
        if ($hash === $this->hash()) {
            $this->setState('builtHash', $hash);
        }
    }

    /**
     * Updates the report for a saved entry: a page, or the page a saved row, block or column is on. Once per page, at the end
     * of the request, so saving a page with all its blocks checks it once, as saved.
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
        $this->queue((int)$page->id, (int)$page->siteId);
    }

    /**
     * Updates the report for a page in every site, e.g. after it was restored from the trash.
     */
    public function refreshAllSites(Entry $entry): void
    {
        if ($entry->getSection() === null || $entry->getIsDraft() || $entry->getIsRevision()) {
            return;
        }
        foreach ($entry->getSupportedSites() as $site) {
            $this->queue((int)$entry->id, (int)(is_array($site) ? $site['siteId'] : $site));
        }
    }

    /**
     * Removes a deleted page from the report: in every site, or in one (deleted for a site).
     */
    public function forget(Entry $entry, ?int $siteId = null): void
    {
        Db::delete(self::TABLE, array_filter(['entryId' => (int)$entry->id, 'siteId' => $siteId], fn($value) => $value !== null));
    }

    /**
     * The hash of everything that decides what's an issue: the outline rules (Outline::VERSION), the settings, Trinity's
     * settings, and the project config (field layouts, conditions). A new hash builds a new list.
     */
    public function hash(): string
    {
        if ($this->hash !== null) {
            return $this->hash;
        }
        $settings = HeadingsChecker::getInstance()->getSettings();
        $rows = HeadingsChecker::getInstance()->getOutline()->rows();
        $trinity = $rows?->getSettings();
        return $this->hash = md5((string)json_encode([
            Outline::VERSION,
            $settings->fields,
            $settings->titleFields,
            $settings->textFields,
            $settings->cardTypes,
            array_map(fn($heading) => is_string($heading) ? $heading : 'callable', $settings->rowHeadings),
            $settings->rowHeadingCategory,
            $settings->showTitleField,
            $settings->pageTitle,
            $settings->backgroundField,
            $trinity ? [
                $trinity->rowEntryType,
                $trinity->cellsField,
                $trinity->rowBackgroundField,
                $trinity->rowBackgroundDefault,
                $trinity->rowSpacingField,
            ] : null,
            Craft::$app->getProjectConfig()->get('dateModified'),
        ]));
    }

    /**
     * @param array<array<string, mixed>> $issues
     */
    private function store(int $entryId, int $siteId, array $issues): void
    {
        if (!$issues) {
            Db::delete(self::TABLE, ['entryId' => $entryId, 'siteId' => $siteId]);
            return;
        }
        Db::upsert(self::TABLE, [
            'entryId' => $entryId,
            'siteId' => $siteId,
            'issues' => Json::encode($issues),
        ]);
    }

    private function queue(int $id, int $siteId): void
    {
        // Add it before registering the callback: after the request it runs right away
        $first = !$this->pending;
        $this->pending["$id-$siteId"] = [$id, $siteId];
        if ($first) {
            Craft::$app->onAfterRequest(fn() => $this->flush());
        }
    }

    /**
     * Updates the report for the pages saved so far in this request (or queue job).
     */
    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as [$id, $siteId]) {
            $page = Entry::find()->id($id)->siteId($siteId)->status(null)->one();
            if ($page) {
                $this->checkPage($page);
            } else {
                Db::delete(self::TABLE, ['entryId' => $id, 'siteId' => $siteId]);
            }
        }
    }

    private function state(string $name): ?string
    {
        $value = (new Query())->select(['value'])->from(self::STATE_TABLE)->where(['name' => $name])->scalar();
        return $value === false || $value === null ? null : (string)$value;
    }

    private function setState(string $name, string $value): void
    {
        Db::upsert(self::STATE_TABLE, ['name' => $name, 'value' => $value]);
    }
}
