<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scope_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_scope_id');
            $table->foreignId('job_site_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreign(['job_scope_id', 'job_site_id'])
                ->references(['id', 'job_site_id'])->on('job_scopes')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedInteger('size_bytes');
            $table->string('caption', 500)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestampTz('taken_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->index(['job_scope_id', 'created_at']);
            $table->index('job_site_id');
            $table->index('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scope_photos');
    }
};
