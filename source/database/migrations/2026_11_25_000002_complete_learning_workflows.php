<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_assignment_overrides', function (Blueprint $table) {
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('granted');
            $table->boolean('baseline_granted');
            $table->primary(['activity_id', 'user_id']);
        });
        Schema::table('classrooms', function (Blueprint $table) {
            $table->timestampTz('archived_at')->nullable();
        });
        Schema::create('lti_selection_receipts', function (Blueprint $table) {
            $table->char('state_hash', 64)->primary();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lti_selection_receipts');
        Schema::table('classrooms', fn (Blueprint $table) => $table->dropColumn('archived_at'));
        Schema::dropIfExists('activity_assignment_overrides');
    }
};
