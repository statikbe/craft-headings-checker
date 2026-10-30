<?php

namespace statikbe\headingschecker\migrations;

use craft\db\Migration;
use craft\db\Table;
use statikbe\headingschecker\services\Report;

/**
 * The report of the Heading issues widget: one row per page and site with issues, the pages saved since (to check them in a
 * queue job), and the state of the full build.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        self::createTables($this);
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Report::PENDING_TABLE);
        $this->dropTableIfExists(Report::TABLE);
        $this->dropTableIfExists(Report::STATE_TABLE);
        return true;
    }

    /**
     * Creates the tables and columns that are missing, so it also upgrades an older install (m261030_120000_report_table,
     * m261031_000000_pending_pages).
     */
    public static function createTables(Migration $migration): void
    {
        $db = $migration->db;
        if (!$db->tableExists(Report::TABLE)) {
            $migration->createTable(Report::TABLE, [
                'id' => $migration->primaryKey(),
                'entryId' => $migration->integer()->notNull(),
                'siteId' => $migration->integer()->notNull(),
                'issues' => $migration->longText()->notNull(),
                'buildId' => $migration->string(16),
                'dateCreated' => $migration->dateTime()->notNull(),
                'dateUpdated' => $migration->dateTime()->notNull(),
                'uid' => $migration->uid(),
            ]);
            $migration->createIndex(null, Report::TABLE, ['entryId', 'siteId'], true);
            $migration->addForeignKey(null, Report::TABLE, ['entryId'], Table::ELEMENTS, ['id'], 'CASCADE');
            $migration->addForeignKey(null, Report::TABLE, ['siteId'], Table::SITES, ['id'], 'CASCADE');
        }
        if ($db->getTableSchema(Report::TABLE, true)?->getColumn('buildId') === null) {
            $migration->addColumn(Report::TABLE, 'buildId', $migration->string(16)->after('issues'));
        }
        if (!$db->tableExists(Report::PENDING_TABLE)) {
            $migration->createTable(Report::PENDING_TABLE, [
                'id' => $migration->primaryKey(),
                'entryId' => $migration->integer()->notNull(),
                'siteId' => $migration->integer()->notNull(),
                'queuedAt' => $migration->bigInteger()->notNull(),
            ]);
            $migration->createIndex(null, Report::PENDING_TABLE, ['entryId', 'siteId'], true);
            $migration->addForeignKey(null, Report::PENDING_TABLE, ['entryId'], Table::ELEMENTS, ['id'], 'CASCADE');
            $migration->addForeignKey(null, Report::PENDING_TABLE, ['siteId'], Table::SITES, ['id'], 'CASCADE');
        }
        if (!$db->tableExists(Report::STATE_TABLE)) {
            $migration->createTable(Report::STATE_TABLE, [
                'name' => $migration->string(50)->notNull(),
                'value' => $migration->string(255),
                'dateCreated' => $migration->dateTime()->notNull(),
                'dateUpdated' => $migration->dateTime()->notNull(),
                'uid' => $migration->uid(),
                'PRIMARY KEY([[name]])',
            ]);
        }
    }
}
