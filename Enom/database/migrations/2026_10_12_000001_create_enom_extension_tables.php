<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enom_order_intents', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('server_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('plan_id');
            $table->string('domain', 253);
            $table->string('sld', 63);
            $table->string('tld', 63);
            $table->string('action', 32);
            $table->unsignedSmallInteger('term')->default(1);
            $table->decimal('amount', 12, 2);
            $table->decimal('renewal_amount', 12, 2);
            $table->string('currency', 3);
            $table->longText('payload')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->unsignedBigInteger('service_id')->nullable()->unique();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('invoice_item_id')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'expires_at']);
        });
        Schema::create('enom_domains', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_id')->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('server_id');
            $table->string('domain', 253)->unique();
            $table->string('sld', 63);
            $table->string('tld', 63);
            $table->string('status', 32);
            $table->date('provider_expires_at')->nullable();
            $table->string('provider_order_id', 128)->nullable();
            $table->longText('snapshot')->nullable();
            $table->json('capabilities')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('drift_detected_at')->nullable();
            $table->timestamps();
        });
        Schema::create('enom_operations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('domain_id')->index();
            $table->unsignedBigInteger('service_id');
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->unsignedBigInteger('invoice_item_id')->nullable();
            $table->string('operation_key', 191)->unique();
            $table->string('action', 32);
            $table->unsignedSmallInteger('term')->default(1);
            $table->string('funding_basis', 32)->default('paid_invoice');
            $table->string('state', 32)->index();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency', 3);
            $table->date('base_expires_at')->nullable();
            $table->date('expected_expires_at')->nullable();
            $table->date('confirmed_expires_at')->nullable();
            $table->longText('payload')->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->string('safe_code', 64)->nullable();
            $table->string('provider_order_id', 128)->nullable();
            $table->unsignedBigInteger('authorized_by')->nullable();
            $table->string('funding_reason', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['invoice_item_id', 'action']);
        });
    }

    // Data-retaining removal is deliberate. Financial deduplication survives reinstall.
    public function down(): void {}
};
