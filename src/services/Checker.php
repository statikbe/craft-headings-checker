<?php

namespace statikbe\headingschecker\services;

use Craft;
use craft\base\Component;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use statikbe\headingschecker\HeadingsChecker;

/**
 * The heading check when an entry is published (settings → mode).
 */
class Checker extends Component
{
    /** @var array<string, array<array{field: string, message: string}>> errors per block or column (uid) of a page that wasn't saved */
    private array $cellErrors = [];

    /**
     * After an entry is validated (Entry::EVENT_AFTER_VALIDATE). Only for pages (entries in a section), in the live scenario
     * (publishing): not for drafts, autosaves, propagation to other sites, resaving, revisions or console commands.
     */
    public function check(Entry $entry): void
    {
        // A block or column of a page that wasn't saved: the control panel validates its card again to show it, which clears
        // its errors
        if ($entry->uid !== null && isset($this->cellErrors[$entry->uid])) {
            foreach ($this->cellErrors[$entry->uid] as $error) {
                $entry->addError($error['field'], $error['message']);
            }
        }

        $mode = HeadingsChecker::getInstance()->getSettings()->checkMode();
        if (
            $mode === false
            || $entry->getSection() === null
            || $entry->getScenario() !== Entry::SCENARIO_LIVE
            || $entry->propagating
            || $entry->resaving
            || $entry->getIsRevision()
            || Craft::$app->getRequest()->getIsConsoleRequest()
        ) {
            return;
        }

        $outline = HeadingsChecker::getInstance()->getOutline();
        $result = $outline->pageOutline($entry, true);
        if (!$result['issues']) {
            return;
        }

        if ($mode === 'warn') {
            if (!$entry->hasErrors()) {
                Craft::$app->getSession()->setError(Craft::t('headings-checker', 'Check the heading levels: {issues}', [
                    'issues' => implode(' ', array_map(fn(array $issue) => $outline->message($issue), $result['issues'])),
                ]));
            }
            return;
        }

        // The control panel draws the rows from the builder's cached result after a failed save, or queries them again when
        // they weren't posted (unchanged): cache the rows, so it draws the ones that get the errors
        $cached = false;
        foreach (array_unique(array_column(array_filter($result['places'], fn(array $place) => $place['row'] !== null), 'field')) as $field) {
            $value = $entry->getFieldValue($field);
            if ($value instanceof ElementQueryInterface && $value->getCachedResult() === null) {
                $value->setCachedResult((clone $value)->drafts(null)->savedDraftsOnly()->status(null)->limit(null)->all());
                $cached = true;
            }
        }
        if ($cached) {
            $result = $outline->pageOutline($entry, true);
        }

        foreach ($result['places'] as $i => $place) {
            // Craft renders validation errors as Markdown
            $this->addIssueError($entry, $place, $outline->message($result['issues'][$i], true));
        }
    }

    /**
     * Puts an issue where it is, so the control panel marks it as Craft does for its own errors:
     * - a text field of the page: on that field;
     * - a Trinity row: on the row's cells field (the page's error list links to it, the row shows it under its columns), and
     *   on the column, whose card turns red;
     * - another block: on its field with the heading (blocks view shows it there; in cards view the card turns red);
     * - without uids (an entry that was never saved): on the content builder field.
     *
     * @param array{field: string, row: Entry|null, cell: Entry|null, isRow: bool, cellField: string|null} $place
     */
    private function addIssueError(Entry $page, array $place, string $message): void
    {
        $field = $place['field'];
        $row = $place['row'];
        $cell = $place['cell'];
        $cellField = $place['cellField'] ?? 'title';
        $rows = HeadingsChecker::getInstance()->getOutline()->rows();
        if ($row === null || $row->uid === null || ($place['isRow'] && ($cell === null || $cell->uid === null || $rows === null))) {
            $page->addError($field, $message);
            return;
        }

        if (!$place['isRow']) {
            $row->addError($cellField, $message);
            $page->addError(sprintf('%s[%s].%s', $field, $row->uid, $cellField), $message);
            $this->cellErrors[$row->uid][] = ['field' => $cellField, 'message' => $message];
            if ($row->id) {
                $page->addInvalidNestedElementIds([$row->id]);
            }
            return;
        }

        $cellsField = $rows->getSettings()->cellsField;
        $page->addError(sprintf('%s[%s].%s', $field, $row->uid, $cellsField), $message);
        $row->addError($cellsField, $message);
        // The row needs an error on one of its columns, or the control panel doesn't validate the cards again
        $row->addError(sprintf('%s[%s].%s', $cellsField, $cell->uid, $cellField), $message);
        $cell->addError($cellField, $message);
        $this->cellErrors[$cell->uid][] = ['field' => $cellField, 'message' => $message];
    }
}
