<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table): void {
            $table->foreignId('job_scope_id')->nullable();
            $table->foreign(['job_scope_id', 'job_site_id'])->references(['id', 'job_site_id'])->on('job_scopes')->restrictOnDelete();
            $table->string('role_required')->nullable();
            $table->string('status')->default('programmed')->index();
            $table->text('notes')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table): void {
            $table->dropForeign(['job_scope_id', 'job_site_id']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropIndex(['status']);
            $table->dropColumn(['job_scope_id', 'role_required', 'status', 'notes', 'cancellation_reason']);
        });
    }
};
