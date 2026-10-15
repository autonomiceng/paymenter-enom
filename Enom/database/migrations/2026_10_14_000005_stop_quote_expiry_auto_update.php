<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MySQL/MariaDB without explicit_defaults_for_timestamp give a table's first non-null TIMESTAMP
     * an implicit ON UPDATE CURRENT_TIMESTAMP. On enom_order_intents that is expires_at, so editing an
     * open quote (for example changing its term) silently expired it. Drop the auto-update.
     */
    public function up(): void
    {
        if (!Schema::hasTable('enom_order_intents') || !in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) { return; }
        DB::statement('ALTER TABLE enom_order_intents MODIFY expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }

    public function down(): void {}
};
