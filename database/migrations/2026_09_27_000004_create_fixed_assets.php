<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->string('depreciation_method', 30)->default('straight_line');
            $table->unsignedInteger('useful_life_months');
            $table->decimal('salvage_percent', 8, 4)->default(0);
            $table->string('asset_account_code', 40)->default('AUTO-FIXED-ASSET');
            $table->string('accumulated_depreciation_account_code', 40)->default('AUTO-ACCUM-DEP');
            $table->string('depreciation_expense_account_code', 40)->default('AUTO-DEP-EXP');
            $table->timestamps();

            $table->unique(['business_id', 'code']);
        });

        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained()->nullOnDelete();
            $table->string('asset_number', 80);
            $table->string('name');
            $table->string('serial_number')->nullable();
            $table->string('location')->nullable();
            $table->date('acquisition_date');
            $table->date('in_service_date');
            $table->decimal('acquisition_cost', 16, 4);
            $table->decimal('salvage_value', 16, 4)->default(0);
            $table->unsignedInteger('useful_life_months');
            $table->string('depreciation_method', 30)->default('straight_line');
            $table->string('status', 30)->default('active');
            $table->decimal('accumulated_depreciation', 16, 4)->default(0);
            $table->decimal('book_value', 16, 4);
            $table->date('last_depreciated_through')->nullable();
            $table->date('disposed_at')->nullable();
            $table->decimal('disposal_proceeds', 16, 4)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'asset_number']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'in_service_date']);
        });

        Schema::create('asset_depreciation_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount', 16, 4);
            $table->decimal('accumulated_after', 16, 4);
            $table->decimal('book_value_after', 16, 4);
            $table->timestamps();

            $table->unique(['fixed_asset_id', 'period_end']);
            $table->index(['business_id', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_depreciation_entries');
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('asset_categories');
    }
};
