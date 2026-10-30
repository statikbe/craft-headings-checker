<?php

namespace statikbe\headingschecker\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use statikbe\headingschecker\HeadingsChecker;
use statikbe\headingschecker\jobs\BuildReportJob;
use statikbe\headingschecker\jobs\UpdatePagesJob;

/**
 * The pages with heading issues, for the Heading issues dashboard widget: one row per page and site in a table.
 *
 * - A saved, restored or propagated page is added to the pending table at the end of the request (or queue job), and one
 *   queue job at a time (UpdatePagesJob) checks the pending pages, a site at a time, so their rows and columns load together.
 *   Nothing is checked in the editor's request.
 * - The full list is built by a queue job (BuildReportJob) or by `craft headings-checker/check`, never in a web request:
 *   after installing, and when something that decides what's an issue changes (hash()). The previous list stays visible
 *   while it's built: each build has an id, every row it writes carries it, and at the end the rows of an older build (pages
 *   that are gone or no longer apply) are removed. A newer build stops an older one.
 *
 * The issues are stored as data (Outline::pageOutline() → issues) and turned into messages when they're shown, in the
 * language of the user who reads them.
 */
class Report extends Component
{
    public const TABLE = '{{%headingschecker_pages}}';
    public const STATE_TABLE = '{{%headingschecker_state}}';
    public const PENDING_TABLE = '{{%headingschecker_pending}}';

    /** Priority of the plugin's queue jobs: after Craft's own and the editors' (lower runs first; Craft's default is 1024) */
    public const PRIORITY = 2048;

    /** Seconds a batch of the plugin's queue jobs may take; the batched jobs stop before it and go on in a new job */
    public const TTR = 300;

    /** Seconds after which a build that stopped reporting progress is queued again (the older one then stops) */
    private const STALLED = 900;

    /** Set while an UpdatePagesJob is waiting or running, so saves don't push another one */
    private const UPDATE_QUEUED_KEY = 'headings-checker:update-queued';

    /** @var array<string, array{0: int, 1: int}> pages to add to the pending table at the end of the request or job */
    private array $pending = [];

    /** Whether flushPending() runs at the end of the request (once per process) */
    private bool $flushRegistered = false;

    /** @var array<string, string> the hash per project config date (hash()) */
    private array $hashes = [];

    /**
     * The state of the list for the current settings: 'built', 'rebuilding' (an older list is shown while a new one is built)
     * or 'building' (there's no list yet). Queues a build when the list isn't built for the current settings and none is
     * running.
     *
     * @return 'built'|'rebuilding'|'building'
     */
    public function ensureBuilt(): string
    {
        $hash = $this->hash();
        $builtHash = $this->state('builtHash');
        if ($builtHash === $hash) {
            return 'built';
        }
        $mutex = Craft::$app->getMutex();
        if ($mutex->acquire('headings-checker.report.queue', 5)) {
            try {
                $heartbeat = (int)$this->state('heartbeat');
                if ($this->state('queuedHash') !== $hash || $heartbeat < time() - self::STALLED) {
                    // A new build id: a build that's still waiting or running for older settings stops at its next batch
                    $buildId = StringHelper::randomString(16);
                    $this->setState('buildId', $buildId);
                    $this->setState('queuedHash', $hash);
                    $this->setState('heartbeat', (string)time());
                    self::push(new BuildReportJob(['hash' => $hash, 'buildId' => $buildId]));
                }
            } finally {
                $mutex->release('headings-checker.report.queue');
            }
        }
        return $builtHash !== null ? 'rebuilding' : 'building';
    }

    /**
     * Pushes one of the plugin's queue jobs, after Craft's own jobs, with a time limit for each batch.
     */
    public static function push(\craft\queue\BaseJob $job): void
    {
        \craft\helpers\Queue::push($job, self::PRIORITY, null, self::TTR);
    }

    /**
     * The issues of these pages: ["entryId-siteId" => [issue data, …]].
     *
     * @param array<array{0: int, 1: int}> $pages [entry id, site id] pairs
     * @return array<string, array<array<string, mixed>>>
     */
    public function issuesOf(array $pages): array
    {
        if (!$pages) {
            return [];
        }
        $condition = ['or'];
        foreach ($pages as [$entryId, $siteId]) {
            $condition[] = ['entryId' => $entryId, 'siteId' => $siteId];
        }
        $issues = [];
        foreach ((new Query())->select(['entryId', 'siteId', 'issues'])->from(self::TABLE)->where($condition)->all() as $row) {
            $issues[$row['entryId'] . '-' . $row['siteId']] = Json::decodeIfJson((string)$row['issues']) ?: [];
        }
        return $issues;
    }

    /**
     * Checks pages and stores the results: a row for each page with issues, none for the others. The pages of one site, loaded
     * together, so their rows and columns are loaded a level at a time.
     *
     * @param Entry[] $pages
     * @param string|null $buildId the build the rows belong to (null: the current one)
     * @return array<int, array<array<string, mixed>>|null> per page (same keys): its issues, null when it has no text fields
     * and no content builder
     */
    public function checkPages(array $pages, ?string $buildId = null): array
    {
        $outline = HeadingsChecker::getInstance()->getOutline();
        $results = [];
        foreach ($pages as $key => $page) {
            try {
                $result = $outline->pageOutline($page, true);
            } catch (\Throwable $e) {
                // One page that can't be read doesn't stop the others; it keeps its row
                Craft::warning("Couldn't check the headings of entry $page->id in site $page->siteId: {$e->getMessage()}", __METHOD__);
                continue;
            }
            $results[$key] = $result['applies'] ? $result['issues'] : null;
        }
        $this->store($pages, $results, $buildId ?? $this->state('buildId'));
        return $results;
    }

    /**
     * Checks a page and stores the result.
     *
     * @return array<array<string, mixed>>|null the issues, null when it has no text fields and no content builder
     */
    public function checkPage(Entry $page): ?array
    {
        return $this->checkPages([$page])[0] ?? null;
    }

    /**
     * A build starts (the console command): it becomes the current one, so a build in the queue stops.
     */
    public function startBuild(string $hash): string
    {
        $buildId = StringHelper::randomString(16);
        $this->setState('buildId', $buildId);
        $this->setState('queuedHash', $hash);
        $this->heartbeat();
        return $buildId;
    }

    /**
     * Whether a build is still the current one (no newer one was started).
     */
    public function isCurrentBuild(string $buildId): bool
    {
        return $this->state('buildId') === $buildId;
    }

    /**
     * Keeps a running build from being queued again.
     */
    public function heartbeat(): void
    {
        $this->setState('heartbeat', (string)time());
    }

    /**
     * After a full build: the rows of older builds go, and the list is ready for the hash it was built for. Not when a newer
     * build started meanwhile; that one finishes the list.
     */
    public function finishBuild(string $hash, string $buildId): void
    {
        $mutex = Craft::$app->getMutex();
        $mutex->acquire('headings-checker.report.queue', 15);
        try {
            if (!$this->isCurrentBuild($buildId)) {
                return;
            }
            Db::delete(self::TABLE, ['or', ['buildId' => null], ['not', ['buildId' => $buildId]]]);
            $this->setState('builtHash', $hash);
        } finally {
            $mutex->release('headings-checker.report.queue');
        }
    }

    /**
     * Updates the report for a saved entry: a page, or the page a saved row, block or column is on. The page is added to the
     * pending table at the end of the request (once per page, as saved) and checked in a queue job. Not for drafts,
     * revisions or resaving (`craft resave`; a project config change rebuilds the whole list anyway).
     */
    public function refresh(Entry $entry): void
    {
        if ($entry->getIsDraft() || $entry->getIsRevision() || !$entry->getIsCanonical() || $entry->resaving) {
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
     * Adds the pages saved so far to the pending table and pushes the job that checks them (HeadingsChecker also calls this
     * after every queue job).
     */
    public function flushPending(): void
    {
        if (!$this->pending) {
            return;
        }
        $pages = array_values($this->pending);
        $this->pending = [];
        try {
            $this->queuePages($pages);
        } catch (\Throwable $e) {
            // Never fail a save that's done
            Craft::error("Couldn't queue the heading check of pages: {$e->getMessage()}", __METHOD__);
        }
    }

    /**
     * Marks pages to be checked again and pushes the job that checks them, unless one is waiting or running already (it
     * checks the pages added while it runs too).
     *
     * @param array<array{0: int, 1: int}> $pages [entry id, site id] pairs
     */
    public function queuePages(array $pages): void
    {
        $queuedAt = (int)(microtime(true) * 1_000_000);
        foreach (array_chunk($pages, 250) as $chunk) {
            $condition = ['or'];
            foreach ($chunk as [$entryId, $siteId]) {
                $condition[] = ['entryId' => $entryId, 'siteId' => $siteId];
            }
            $existing = [];
            foreach ((new Query())->select(['id', 'entryId', 'siteId'])->from(self::PENDING_TABLE)->where($condition)->all() as $row) {
                $existing[$row['entryId'] . '-' . $row['siteId']] = (int)$row['id'];
            }
            if ($existing) {
                Db::update(self::PENDING_TABLE, ['queuedAt' => $queuedAt], ['id' => array_values($existing)], [], false);
            }
            $new = array_values(array_filter($chunk, fn(array $page) => !isset($existing[$page[0] . '-' . $page[1]])));
            if ($new) {
                try {
                    Db::batchInsert(self::PENDING_TABLE, ['entryId', 'siteId', 'queuedAt'], array_map(fn(array $page) => [$page[0], $page[1], $queuedAt], $new));
                } catch (\Throwable) {
                    // Another request added one of them meanwhile
                    foreach ($new as [$entryId, $siteId]) {
                        Db::upsert(self::PENDING_TABLE, ['entryId' => $entryId, 'siteId' => $siteId, 'queuedAt' => $queuedAt]);
                    }
                }
            }
        }
        $this->queueIfPending();
    }

    /**
     * Pushes the job that checks the pending pages, unless one is waiting or running.
     */
    public function queueIfPending(): void
    {
        $cache = Craft::$app->getCache();
        if ($cache->exists(self::UPDATE_QUEUED_KEY) || !(new Query())->from(self::PENDING_TABLE)->exists()) {
            return;
        }
        if ($cache->add(self::UPDATE_QUEUED_KEY, true, 3600)) {
            self::push(new UpdatePagesJob());
        }
    }

    /**
     * A batch of the update job started: it's still running (the key expires an hour after the last batch, when a job died).
     */
    public function keepUpdateQueued(): void
    {
        Craft::$app->getCache()->set(self::UPDATE_QUEUED_KEY, true, 3600);
    }

    /**
     * The update job is done: pages saved since its last batch get a new one.
     */
    public function updateFinished(): void
    {
        Craft::$app->getCache()->delete(self::UPDATE_QUEUED_KEY);
        $this->queueIfPending();
    }

    /**
     * The next pending pages after a pending row id: [['id', 'entryId', 'siteId', 'queuedAt'], …].
     *
     * @return array<array{id: int|string, entryId: int|string, siteId: int|string, queuedAt: int|string}>
     */
    public function pendingPages(int $afterId, int $limit): array
    {
        return (new Query())
            ->select(['id', 'entryId', 'siteId', 'queuedAt'])
            ->from(self::PENDING_TABLE)
            ->where(['>', 'id', $afterId])
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit)
            ->all();
    }

    /**
     * Checks pending pages (of one site or more) and removes them from the pending table, unless one was saved again meanwhile
     * (then it's checked again).
     *
     * @param array<array{id: int|string, entryId: int|string, siteId: int|string, queuedAt: int|string}> $rows
     */
    public function checkPending(array $rows): void
    {
        $bySite = [];
        foreach ($rows as $row) {
            $bySite[(int)$row['siteId']][(int)$row['entryId']] = $row;
        }
        foreach ($bySite as $siteId => $siteRows) {
            $pages = Entry::find()->id(array_keys($siteRows))->siteId($siteId)->status(null)->all();
            $found = [];
            foreach ($pages as $page) {
                $found[(int)$page->id] = true;
            }
            $this->checkPages($pages);
            // Gone meanwhile (deleted, or no longer in this site)
            $gone = array_values(array_diff(array_keys($siteRows), array_keys($found)));
            if ($gone) {
                Db::delete(self::TABLE, ['entryId' => $gone, 'siteId' => $siteId]);
            }
        }
        // Pages saved at the same time are removed together
        $byTime = [];
        foreach ($rows as $row) {
            $byTime[(int)$row['queuedAt']][] = (int)$row['id'];
        }
        foreach ($byTime as $queuedAt => $ids) {
            Db::delete(self::PENDING_TABLE, ['and', ['id' => $ids], ['<=', 'queuedAt', $queuedAt]]);
        }
    }

    /**
     * The ids of the entry types whose pages can have an outline: a content builder (settings → fields) or a text field
     * (settings → textFields) in their field layout. Only their entries are checked when the list is built.
     *
     * @return int[]
     */
    public function pageTypeIds(): array
    {
        $settings = HeadingsChecker::getInstance()->getSettings();
        $ids = [];
        foreach (Craft::$app->getEntries()->getAllEntryTypes() as $entryType) {
            $layout = $entryType->getFieldLayout();
            $handles = array_merge($settings->fields, $settings->textFieldsFor($entryType->handle));
            foreach ($handles as $handle) {
                if ($layout->getFieldByHandle($handle) !== null) {
                    $ids[] = (int)$entryType->id;
                    break;
                }
            }
        }
        return $ids;
    }

    /**
     * The hash of everything that decides what's an issue: the outline rules (Outline::VERSION), the settings, Trinity's
     * settings, and the parts of the project config that hold the fields, entry types (field layouts, conditions), sections
     * and sites. Other project config changes (an image transform, a user group, another plugin's settings) don't rebuild
     * the list. Worked out once per project config change.
     */
    public function hash(): string
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $date = (string)$projectConfig->get('dateModified');
        if (isset($this->hashes[$date])) {
            return $this->hashes[$date];
        }
        $settings = HeadingsChecker::getInstance()->getSettings();
        $rows = HeadingsChecker::getInstance()->getOutline()->rows();
        $trinity = $rows?->getSettings();
        $settingsPart = [
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
        ];
        $configPart = Craft::$app->getCache()->getOrSet("headings-checker:config-hash:$date", fn() => md5((string)json_encode([
            $projectConfig->get('fields'),
            $projectConfig->get('entryTypes'),
            $projectConfig->get('sections'),
            $projectConfig->get('sites'),
        ])), 86400 * 30);
        return $this->hashes[$date] = md5((string)json_encode([$settingsPart, $configPart]));
    }

    /**
     * Stores the results of pages (of one site or more): the rows of the pages with issues are written, the others removed,
     * in a few queries.
     *
     * @param Entry[] $pages
     * @param array<int|string, array<array<string, mixed>>|null> $results per page key
     */
    private function store(array $pages, array $results, ?string $buildId): void
    {
        $clean = [];
        $rows = [];
        foreach ($pages as $key => $page) {
            if (!array_key_exists($key, $results)) {
                continue;
            }
            $issues = $results[$key] ?? [];
            if ($issues) {
                $rows[] = [(int)$page->id, (int)$page->siteId, Json::encode($issues)];
            } else {
                $clean[(int)$page->siteId][] = (int)$page->id;
            }
        }
        foreach ($clean as $siteId => $ids) {
            Db::delete(self::TABLE, ['entryId' => $ids, 'siteId' => $siteId]);
        }
        foreach ($rows as [$entryId, $siteId, $issues]) {
            Db::upsert(self::TABLE, [
                'entryId' => $entryId,
                'siteId' => $siteId,
                'issues' => $issues,
                'buildId' => $buildId,
            ]);
        }
    }

    private function queue(int $id, int $siteId): void
    {
        if (!$this->flushRegistered) {
            // At the end of the request; after a queue job (an import) too, which ends long before its worker does
            $this->flushRegistered = true;
            Craft::$app->onAfterRequest(fn() => $this->flushPending());
        }
        $this->pending["$id-$siteId"] = [$id, $siteId];
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
