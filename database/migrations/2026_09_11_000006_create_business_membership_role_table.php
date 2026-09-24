<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membership <-> role mapping (Batch 7).
     *
     * Roles are attached to BUSINESS MEMBERSHIPS, never directly to users, so
     * the same user can hold different roles in different businesses. The
     * unique (membership_id, role_id) pair prevents duplicate assignments.
     *
     * Cross-business assignments cannot be expressed as a simple foreign key
     * (the role's business must equal the membership's business), so they are
     * enforced in application logic (BusinessMembership::assignRole) and again
     * at authorization time by filtering roles to the membership's business.
     */
    public function up(): void
    {
        Schema::create('business_membership_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained('business_memberships')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['membership_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_membership_role');
    }
};
