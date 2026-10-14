<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enom_tlds', function (Blueprint $table) {
            if (!Schema::hasColumn('enom_tlds', 'min_years')) { $table->unsignedTinyInteger('min_years')->default(1); }
            if (!Schema::hasColumn('enom_tlds', 'max_years')) { $table->unsignedTinyInteger('max_years')->default(10); }
            // Normalized registry fields from GetExtAttributes (for example .us nexus), stored when enabled.
            if (!Schema::hasColumn('enom_tlds', 'registry_fields')) { $table->longText('registry_fields')->nullable(); }
        });
        Schema::table('enom_domains', function (Blueprint $table) {
            // Reminder offsets already sent, keyed by expiry date, so a cycle never repeats one.
            if (!Schema::hasColumn('enom_domains', 'reminders')) { $table->longText('reminders')->nullable(); }
        });
    }

    // Retain data through uninstall/downgrade.
    public function down(): void {}
};
