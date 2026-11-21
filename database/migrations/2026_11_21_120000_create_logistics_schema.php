<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 40)->nullable();
            $table->text('insurance_reference')->nullable();
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->text('emergency_contact')->nullable();
        });

        Schema::create('job_sites', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('address');
            $table->string('client');
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->unsignedInteger('geofence_meters')->default(150);
            $table->string('status')->default('planned')->index();
            $table->decimal('base_budget', 14, 2)->default(0);
            $table->decimal('approved_adjustment', 14, 2)->default(0);
            $table->softDeletes();
            $table->timestampsTz();
        });

        Schema::create('job_site_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['job_site_id', 'user_id']);
            $table->index(['user_id', 'job_site_id']);
            $table->timestampsTz();
        });

        Schema::create('job_scopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_site_id')->constrained()->restrictOnDelete();
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedSmallInteger('progress')->default(0);
            $table->decimal('approved_adjustment', 14, 2)->default(0);
            $table->unsignedInteger('approved_extra_days')->default(0);
            $table->unique(['id', 'job_site_id']);
            $table->index(['job_site_id', 'status']);
            $table->softDeletes();
            $table->timestampsTz();
        });

        Schema::create('change_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_site_id')->constrained()->restrictOnDelete();
            $table->foreignId('job_scope_id');
            $table->foreign(['job_scope_id', 'job_site_id'])->references(['id', 'job_site_id'])->on('job_scopes')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('description');
            $table->decimal('additional_cost', 14, 2)->default(0);
            $table->unsignedInteger('extra_days')->default(0);
            $table->text('schedule_impact')->nullable();
            $table->json('attachments')->nullable();
            $table->string('status')->default('draft');
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->index(['job_site_id', 'status']);
            $table->index(['job_scope_id', 'job_site_id']);
            $table->softDeletes();
            $table->timestampsTz();
        });

        Schema::create('materials', function (Blueprint $table): void {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name')->index();
            $table->string('unit', 30);
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->string('supplier')->nullable();
            $table->decimal('stock', 14, 3)->default(0);
            $table->decimal('minimum_stock', 14, 3)->default(0);
            $table->softDeletes();
            $table->timestampsTz();
        });

        Schema::create('material_lists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_site_id')->constrained()->restrictOnDelete();
            $table->foreignId('job_scope_id')->nullable();
            $table->foreign(['job_scope_id', 'job_site_id'])->references(['id', 'job_site_id'])->on('job_scopes')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('status')->default('pending');
            $table->index(['job_site_id', 'status']);
            $table->index(['assigned_to', 'job_site_id']);
            $table->index(['job_scope_id', 'job_site_id']);
            $table->softDeletes();
            $table->timestampsTz();
        });

        Schema::create('material_list_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('material_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $table->decimal('budgeted_quantity', 14, 3);
            $table->decimal('used_quantity', 14, 3)->default(0);
            $table->unique(['material_list_id', 'material_id']);
            $table->timestampsTz();
        });

        Schema::create('shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_site_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('required_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->unique(['id', 'job_site_id', 'user_id']);
            $table->index(['job_site_id', 'starts_at']);
            $table->index(['user_id', 'starts_at']);
            $table->softDeletes();
            $table->timestampsTz();
        });

        Schema::create('attendances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_site_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->unique();
            $table->foreign(['shift_id', 'job_site_id', 'user_id'])->references(['id', 'job_site_id', 'user_id'])->on('shifts')->restrictOnDelete();
            $table->timestampTz('clocked_in_at');
            $table->timestampTz('clocked_out_at')->nullable();
            $table->decimal('in_latitude', 9, 6);
            $table->decimal('in_longitude', 9, 6);
            $table->decimal('out_latitude', 9, 6)->nullable();
            $table->decimal('out_longitude', 9, 6)->nullable();
            $table->unsignedInteger('regular_minutes')->nullable();
            $table->unsignedInteger('overtime_minutes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->index(['job_site_id', 'clocked_in_at']);
            $table->index(['user_id', 'clocked_in_at']);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('material_list_items');
        Schema::dropIfExists('material_lists');
        Schema::dropIfExists('materials');
        Schema::dropIfExists('change_orders');
        Schema::dropIfExists('job_scopes');
        Schema::dropIfExists('job_site_user');
        Schema::dropIfExists('job_sites');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['phone', 'insurance_reference', 'hourly_rate', 'emergency_contact']);
        });
    }
};
