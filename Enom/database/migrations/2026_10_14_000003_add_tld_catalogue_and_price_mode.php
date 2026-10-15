<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('enom_tlds')) {
            Schema::create('enom_tlds', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('server_id');
                $table->string('tld', 63);
                $table->boolean('enabled')->default(false);
                $table->string('pricing_mode', 32)->default('fixed');
                $table->decimal('markup', 12, 2)->nullable();
                $table->decimal('register_price', 12, 2)->nullable();
                $table->decimal('renew_price', 12, 2)->nullable();
                $table->decimal('transfer_price', 12, 2)->nullable();
                $table->decimal('cost_register', 12, 2)->nullable();
                $table->decimal('cost_renew', 12, 2)->nullable();
                $table->decimal('cost_transfer', 12, 2)->nullable();
                $table->timestamp('costs_synced_at')->nullable();
                $table->unsignedInteger('sort_order')->default(100);
                $table->timestamps();
                $table->unique(['server_id', 'tld']);
            });
        }
        if (!Schema::hasColumn('enom_domains', 'price_mode')) {
            Schema::table('enom_domains', function (Blueprint $table) {
                $table->string('price_mode', 32)->nullable();
            });
            // Domains that existed before 0.3 keep their exact current price. New domains follow the catalogue.
            DB::table('enom_domains')->whereNull('price_mode')->update(['price_mode' => 'custom']);
        }
    }

    // Retain pricing history through uninstall/downgrade.
    public function down(): void {}
};
