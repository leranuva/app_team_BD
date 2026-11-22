<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_sites', function (Blueprint $table): void {
            $table->date('start_date')->nullable()->after('status');
            $table->date('estimated_end_date')->nullable()->after('start_date');
            $table->text('notes')->nullable()->after('estimated_end_date');
        });

        Schema::table('job_scopes', function (Blueprint $table): void {
            $table->date('start_date')->nullable()->after('progress');
            $table->date('estimated_end_date')->nullable()->after('start_date');
            $table->text('notes')->nullable()->after('estimated_end_date');
        });
    }

    public function down(): void
    {
        Schema::table('job_sites', function (Blueprint $table): void {
            $table->dropColumn(['start_date', 'estimated_end_date', 'notes']);
        });

        Schema::table('job_scopes', function (Blueprint $table): void {
            $table->dropColumn(['start_date', 'estimated_end_date', 'notes']);
        });
    }
};
