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
     * After an entry is validated (Entry::EVENT_AFTER_VALIDATE). Only in the live scenario (publishing): not for drafts,
     * autosaves, propagation to other sites, resaving, revisions or console commands.
     */
    public function check(Entry $entry): void
    {
        // A column of a page that wasn't saved: the control panel validates its card again to show it, which clears its errors
        if ($entry->uid !== null && isset($this->cellErrors[$entry->uid])) {
            foreach ($this->cellErrors[$entry->uid] as $error) {
                $entry->addError($error['field'], $error['message']);
            }
        }

        $mode = HeadingsChecker::getInstance()->getSettings()->mode;
        if (
            !in_array($mode, ['warn', 'block'], true)
            || $entry->getScenario() !== Entry::SCENARIO_LIVE
            || $entry->propagating
            || $entry->resaving
            || $entry->getIsRevision()
            || Craft::$app->getRequest()->getIsConsoleRequest()
        ) {
            return;
        }

        $outline = HeadingsChecker::getInstance()->getOutline();
        foreach ($outline->builderFields($entry) as $field) {
            $result = $outline->outline($entry, $field);
            if (!$result['issues']) {
                continue;
            }
            if ($mode === 'warn') {
                if (!$entry->hasErrors()) {
                    Craft::$app->getSession()->setError(Craft::t('headings-checker', 'Check the heading levels: {issues}', [
                        'issues' => implode(' ', $result['issues']),
                    ]));
                }
                continue;
            }

            // The control panel draws the rows from the builder's cached result after a failed save, or queries them again
            // when they weren't posted (unchanged): cache the rows, so it draws the ones that get the errors
            $value = $entry->getFieldValue($field);
            if ($value instanceof ElementQueryInterface && $value->getCachedResult() === null) {
                $value->setCachedResult((clone $value)->drafts(null)->savedDraftsOnly()->status(null)->limit(null)->all());
                $result = $outline->outline($entry, $field);
            }
            foreach ($result['places'] as $i => $place) {
                $this->addIssueError($entry, $field, $place, $result['issues'][$i]);
            }
        }
    }

    /**
     * Puts an issue where it is, so the control panel marks it as Craft does for its own errors:
     * - a Trinity row: on the row's cells field (the page's error list links to it, the row shows it under its columns), and
     *   on the column, whose card turns red;
     * - another block: on its field with the heading (blocks view shows it there);
     * - without uids (an entry that was never saved): on the content builder field.
     *
     * @param array{row: Entry, cell: Entry, isRow: bool, field: string} $place
     */
    private function addIssueError(Entry $page, string $field, array $place, string $message): void
    {
        $row = $place['row'];
        $cell = $place['cell'];
        $rows = HeadingsChecker::getInstance()->getOutline()->rows();
        if ($row->uid === null || ($place['isRow'] && ($cell->uid === null || $rows === null))) {
            $page->addError($field, $message);
            return;
        }

        if (!$place['isRow']) {
            $row->addError($place['field'], $message);
            $page->addError(sprintf('%s[%s].%s', $field, $row->uid, $place['field']), $message);
            return;
        }

        $cellsField = $rows->getSettings()->cellsField;
        $page->addError(sprintf('%s[%s].%s', $field, $row->uid, $cellsField), $message);
        $row->addError($cellsField, $message);
        // The row needs an error on one of its columns, or the control panel doesn't validate the cards again
        $row->addError(sprintf('%s[%s].%s', $cellsField, $cell->uid, $place['field']), $message);
        $cell->addError($place['field'], $message);
        $this->cellErrors[$cell->uid][] = ['field' => $place['field'], 'message' => $message];
    }
}
