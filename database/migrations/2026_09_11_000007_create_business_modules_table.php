<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('module_key', 64);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'module_key']);
            $table->index('module_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_modules');
    }
};
