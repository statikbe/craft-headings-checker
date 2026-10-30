<?php

namespace statikbe\headingschecker\migrations;

use craft\db\Migration;
use craft\db\Table;
use statikbe\headingschecker\services\Report;

/**
 * The report of the Heading issues widget: one row per page and site with issues, and the state of the full build.
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
        $this->dropTableIfExists(Report::TABLE);
        $this->dropTableIfExists(Report::STATE_TABLE);
        return true;
    }

    /**
     * Creates the tables that are missing, so it also upgrades an older install (m261030_120000_report_table).
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
                'dateCreated' => $migration->dateTime()->notNull(),
                'dateUpdated' => $migration->dateTime()->notNull(),
                'uid' => $migration->uid(),
            ]);
            $migration->createIndex(null, Report::TABLE, ['entryId', 'siteId'], true);
            $migration->addForeignKey(null, Report::TABLE, ['entryId'], Table::ELEMENTS, ['id'], 'CASCADE');
            $migration->addForeignKey(null, Report::TABLE, ['siteId'], Table::SITES, ['id'], 'CASCADE');
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
