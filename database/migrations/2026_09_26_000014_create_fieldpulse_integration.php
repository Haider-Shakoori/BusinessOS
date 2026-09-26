<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fieldpulse_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('organization_key', 120)->nullable()->unique();
            $table->boolean('enabled')->default(false);
            $table->uuid('last_seen_tenant_uuid')->nullable();
            $table->dateTime('last_request_at')->nullable();
            $table->timestamps();
        });

        Schema::create('fieldpulse_entity_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 60);
            $table->uuid('fieldpulse_uuid');
            $table->unsignedBigInteger('local_id');
            $table->timestamps();

            $table->unique(
                ['business_id', 'entity_type', 'fieldpulse_uuid'],
                'fieldpulse_link_remote_unique',
            );
            $table->unique(
                ['business_id', 'entity_type', 'local_id'],
                'fieldpulse_link_local_unique',
            );
        });

        Schema::create('fieldpulse_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->uuid('fieldpulse_uuid');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_number', 100);
            $table->dateTime('ordered_at')->nullable();
            $table->string('payment_type', 30)->nullable();
            $table->string('currency_code', 3);
            $table->decimal('subtotal', 16, 4)->default(0);
            $table->decimal('discount_total', 16, 4)->default(0);
            $table->decimal('total', 16, 4)->default(0);
            $table->string('salesman_code', 100)->nullable();
            $table->string('salesman_name', 180)->nullable();
            $table->string('status', 30)->default('received');
            $table->json('payload');
            $table->timestamps();

            $table->unique(['business_id', 'fieldpulse_uuid']);
            $table->index(['business_id', 'status', 'ordered_at']);
            $table->index(['business_id', 'order_number']);
        });

        Schema::create('fieldpulse_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->uuid('fieldpulse_uuid');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('receipt_number', 100);
            $table->dateTime('collected_at')->nullable();
            $table->string('currency_code', 3);
            $table->decimal('amount', 16, 4);
            $table->string('payment_method', 40)->nullable();
            $table->string('reference_number', 191)->nullable();
            $table->string('salesman_code', 100)->nullable();
            $table->string('salesman_name', 180)->nullable();
            $table->boolean('within_geofence')->nullable();
            $table->string('status', 30)->default('received');
            $table->json('payload');
            $table->timestamps();

            $table->unique(['business_id', 'fieldpulse_uuid']);
            $table->index(['business_id', 'status', 'collected_at']);
            $table->index(['business_id', 'receipt_number']);
        });

        Schema::create('fieldpulse_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->uuid('fieldpulse_tenant_uuid')->nullable();
            $table->string('idempotency_key', 191);
            $table->string('event_type', 80);
            $table->uuid('fieldpulse_uuid')->nullable();
            $table->string('status', 30)->default('processing');
            $table->string('external_id', 191)->nullable();
            $table->json('payload');
            $table->text('error_message')->nullable();
            $table->dateTime('received_at');
            $table->timestamps();

            $table->unique(
                ['business_id', 'idempotency_key'],
                'fieldpulse_event_idempotency_unique',
            );
            $table->index(['business_id', 'status', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fieldpulse_events');
        Schema::dropIfExists('fieldpulse_collections');
        Schema::dropIfExists('fieldpulse_orders');
        Schema::dropIfExists('fieldpulse_entity_links');
        Schema::dropIfExists('fieldpulse_integrations');
    }
};
