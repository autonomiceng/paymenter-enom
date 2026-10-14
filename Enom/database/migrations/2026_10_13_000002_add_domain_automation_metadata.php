<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enom_domains', function (Blueprint $table) {
            $table->string('provider_domain_id', 128)->nullable();
            $table->boolean('billing_managed')->default(false);
            $table->longText('renewal_policy')->nullable();
            $table->longText('sponsorship')->nullable();
            $table->longText('observation')->nullable();
            $table->unsignedBigInteger('authority_generation')->default(0);
            $table->unsignedBigInteger('confirmed_revision')->default(0);
        });
        Schema::table('enom_operations', function (Blueprint $table) {
            $table->longText('policy')->nullable();
            $table->longText('cost')->nullable();
        });
    }

    // Retain financial and observation evidence through uninstall/downgrade.
    public function down(): void {}
};
