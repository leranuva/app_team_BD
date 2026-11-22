<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_lists', function (Blueprint $table): void {
            $table->string('code')->nullable()->unique();
            $table->string('title', 200)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('ordered_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('consumed_at')->nullable();
            $table->text('cancelled_reason')->nullable();
            $table->index(['job_scope_id', 'status']);
        });

        Schema::table('material_list_items', function (Blueprint $table): void {
            $table->decimal('requested_quantity', 12, 3)->default(0);
            $table->decimal('approved_quantity', 12, 3)->nullable();
            $table->decimal('delivered_quantity', 12, 3)->nullable();
            $table->decimal('consumed_quantity', 12, 3)->nullable();
            $table->unsignedInteger('unit_cost_cents')->nullable();
            $table->text('notes')->nullable();
            $table->index('material_list_id');
            $table->index('material_id');
        });

        Schema::create('material_list_counters', function (Blueprint $table): void {
            $table->foreignId('job_site_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_seq')->default(0);
            $table->primary(['job_site_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_list_counters');

        Schema::table('material_list_items', function (Blueprint $table): void {
            $table->dropIndex(['material_list_id']);
            $table->dropIndex(['material_id']);
            $table->dropColumn([
                'requested_quantity', 'approved_quantity', 'delivered_quantity',
                'consumed_quantity', 'unit_cost_cents', 'notes',
            ]);
        });

        Schema::table('material_lists', function (Blueprint $table): void {
            $table->dropIndex(['job_scope_id', 'status']);
            $table->dropUnique(['code']);
            $table->dropConstrainedForeignId('requested_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['code', 'title', 'notes', 'ordered_at', 'delivered_at', 'consumed_at', 'cancelled_reason']);
        });
    }
};
