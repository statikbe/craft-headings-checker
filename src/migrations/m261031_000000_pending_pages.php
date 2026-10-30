<?php

namespace statikbe\headingschecker\migrations;

use craft\db\Migration;

/**
 * 1.3.0: saved pages are checked in a queue job (the pending table), and each row of the list carries the build it belongs to,
 * so the list stays visible while it's built again.
 */
class m261031_000000_pending_pages extends Migration
{
    public function safeUp(): bool
    {
        Install::createTables($this);
        return true;
    }

    public function safeDown(): bool
    {
        echo "m261031_000000_pending_pages cannot be reverted.\n";
        return false;
    }
}
