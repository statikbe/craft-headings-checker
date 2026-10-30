<?php

namespace statikbe\headingschecker\migrations;

use craft\db\Migration;

/**
 * 1.2.0: the widget's report moves from the cache to its own tables. The list is built again by a queue job.
 */
class m261030_120000_report_table extends Migration
{
    public function safeUp(): bool
    {
        Install::createTables($this);
        return true;
    }

    public function safeDown(): bool
    {
        echo "m261030_120000_report_table cannot be reverted.\n";
        return false;
    }
}
