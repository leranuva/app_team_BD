<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('change_orders', function (Blueprint $table): void {
            $table->string('code')->nullable()->unique();
            $table->string('title', 200)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->integer('amount_cents')->default(0);
            $table->char('currency', 3)->default('USD');
            $table->integer('time_impact_days')->default(0);
            $table->text('schedule_impact_note')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('cancelled_reason')->nullable();
            $table->timestampTz('applied_to_scope_at')->nullable();
        });

        $this->makeScopeNullable();
        Schema::table('change_orders', function (Blueprint $table): void {
            $table->dropColumn('attachments');
        });

        Schema::create('change_order_counters', function (Blueprint $table): void {
            $table->foreignId('job_site_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_seq')->default(0);
            $table->primary(['job_site_id', 'year']);
        });

        Schema::create('change_order_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('change_order_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedInteger('size_bytes');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index('change_order_id');
        });

        foreach (['job_scopes', 'job_sites'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->integer('approved_change_orders_cents')->default(0);
                $table->integer('approved_time_impact_days')->default(0);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('change_order_attachments');
        Schema::dropIfExists('change_order_counters');

        foreach (['job_scopes', 'job_sites'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn(['approved_change_orders_cents', 'approved_time_impact_days']);
            });
        }

        Schema::table('change_orders', function (Blueprint $table): void {
            $table->dropUnique(['code']);
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn([
                'code', 'title', 'amount_cents', 'currency', 'time_impact_days', 'schedule_impact_note',
                'submitted_at', 'rejected_at', 'rejection_reason', 'cancelled_reason', 'applied_to_scope_at',
            ]);
            $table->json('attachments')->nullable();
        });
    }

    private function makeScopeNullable(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('alter table change_orders alter column job_scope_id drop not null');

            return;
        }

        Schema::table('change_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('job_scope_id')->nullable()->change();
        });
    }
};
