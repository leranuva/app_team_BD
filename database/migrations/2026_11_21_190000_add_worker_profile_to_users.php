<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('primary_job_site_id')->nullable()->constrained('job_sites')->nullOnDelete();
            $table->jsonb('identity_documents')->nullable();
            $table->jsonb('osha_documents')->nullable();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('primary_job_site_id');
            $table->dropIndex(['is_active']);
            $table->dropColumn(['address', 'is_active', 'identity_documents', 'osha_documents']);
            $table->dropSoftDeletes();
        });
    }
};
