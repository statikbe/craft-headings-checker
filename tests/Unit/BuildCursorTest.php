<?php

use statikbe\headingschecker\helpers\BuildCursor;

/**
 * Walks a cursor over pages per site (ids per site, in order) with a batch size, like BuildReportJob, and returns the batches.
 *
 * @param array<int, int[]> $pages
 * @return array<array{0: int, 1: int[]}> [site id, page ids] per batch
 */
function buildBatches(array $pages, int $batchSize, int $siteId = 0, int $lastId = 0): array
{
    $cursor = new BuildCursor(array_keys($pages), $siteId, $lastId);
    $batches = [];
    while (($site = $cursor->siteId()) !== null && count($batches) < 100) {
        $ids = array_values(array_slice(array_filter($pages[$site] ?? [], fn(int $id) => $id > $cursor->lastId()), 0, $batchSize));
        $batches[] = [$site, $ids];
        $cursor->advance($ids, $batchSize);
    }
    return $batches;
}

it('checks every page of every site once, a site at a time', function() {
    $batches = buildBatches([1 => [3, 5, 8, 9, 12], 2 => [3, 5], 3 => []], 2);
    expect($batches)->toBe([
        [1, [3, 5]],
        [1, [8, 9]],
        [1, [12]],
        [2, [3, 5]],
        [2, []],
        [3, []],
    ]);
});

it('goes on where a job stopped', function() {
    expect(buildBatches([1 => [3, 5, 8], 2 => [4, 6]], 2, 1, 5))->toBe([
        [1, [8]],
        [2, [4, 6]],
        [2, []],
    ]);
});

it("doesn't skip a page when one before it was deleted", function() {
    // Page 5 is gone when the job goes on after it: an offset would skip page 8
    expect(buildBatches([1 => [3, 8, 9]], 2, 1, 5))->toBe([
        [1, [8, 9]],
        [1, []],
    ]);
});

it('goes on with the next site when its site is gone', function() {
    expect(buildBatches([1 => [3], 4 => [7]], 5, 2, 9))->toBe([[4, [7]]]);
    expect(buildBatches([1 => [3]], 5, 2, 9))->toBe([]);
});

it('has nothing to do without sites', function() {
    expect((new BuildCursor([]))->siteId())->toBeNull();
});
