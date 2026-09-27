<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->foreignId('stock_movement_id')
                ->nullable()
                ->after('product_variant_id')
                ->constrained('stock_movements')
                ->nullOnDelete();
        });

        Schema::create('landed_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goods_receipt_id')->constrained()->restrictOnDelete();
            $table->string('number', 80);
            $table->string('status', 30)->default('draft');
            $table->date('cost_date');
            $table->string('allocation_method', 20);
            $table->decimal('total', 20, 4)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'status', 'cost_date'], 'landed_cost_business_status_date_idx');
            $table->index(['business_id', 'goods_receipt_id'], 'landed_cost_business_receipt_idx');
        });

        Schema::create('landed_cost_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landed_cost_id')->constrained()->cascadeOnDelete();
            $table->string('category', 30);
            $table->string('description')->nullable();
            $table->decimal('amount', 20, 4);
            $table->timestamps();

            $table->index(['landed_cost_id', 'category']);
        });

        Schema::create('landed_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landed_cost_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goods_receipt_item_id')->constrained()->restrictOnDelete();
            $table->decimal('basis_amount', 20, 4)->default(0);
            $table->decimal('allocated_amount', 20, 4);
            $table->decimal('unit_cost_increment', 20, 4);
            $table->decimal('final_unit_cost', 20, 4);
            $table->timestamps();

            $table->unique(['landed_cost_id', 'goods_receipt_item_id'], 'landed_cost_receipt_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landed_cost_allocations');
        Schema::dropIfExists('landed_cost_charges');
        Schema::dropIfExists('landed_costs');

        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_movement_id');
        });
    }
};
