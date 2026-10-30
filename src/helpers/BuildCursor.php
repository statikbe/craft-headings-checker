<?php

namespace statikbe\headingschecker\helpers;

/**
 * Where a build of the list is: a site, and the id of the last page checked in it. Pages are checked site by site in order
 * of id, so a batch only holds pages of one site (their rows and columns load together) and a page deleted meanwhile doesn't
 * make the build skip another one, as an offset would.
 */
class BuildCursor
{
    /** @var int[] */
    private array $siteIds;

    private ?int $siteId;

    private int $lastId;

    /**
     * @param int[] $siteIds every site, in order
     * @param int $siteId the site to go on with (0, or a site that's gone: the first one after it, or the first one)
     * @param int $lastId the last page checked in that site
     */
    public function __construct(array $siteIds, int $siteId = 0, int $lastId = 0)
    {
        $this->siteIds = array_values(array_map('intval', $siteIds));
        sort($this->siteIds);
        $this->siteId = null;
        $this->lastId = 0;
        foreach ($this->siteIds as $id) {
            if ($id === $siteId) {
                $this->siteId = $id;
                $this->lastId = $lastId;
                break;
            }
            if ($id > $siteId) {
                $this->siteId = $id;
                break;
            }
        }
        if ($siteId === 0) {
            $this->siteId = $this->siteIds[0] ?? null;
            $this->lastId = 0;
        }
    }

    /**
     * The site to check next, null when every site is done.
     */
    public function siteId(): ?int
    {
        return $this->siteId;
    }

    /**
     * The id of the last page checked in the current site.
     */
    public function lastId(): int
    {
        return $this->lastId;
    }

    /**
     * After a batch: the ids of its pages (in order). A batch smaller than the batch size was the last of its site.
     *
     * @param int[] $ids
     */
    public function advance(array $ids, int $batchSize): void
    {
        if ($ids) {
            $this->lastId = max($ids);
        }
        if (count($ids) < $batchSize) {
            $this->nextSite();
        }
    }

    private function nextSite(): void
    {
        $index = array_search($this->siteId, $this->siteIds, true);
        $this->siteId = $index === false ? null : ($this->siteIds[$index + 1] ?? null);
        $this->lastId = 0;
    }
}
