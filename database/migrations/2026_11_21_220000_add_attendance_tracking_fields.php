<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->decimal('in_accuracy_meters', 12, 2)->nullable();
            $table->decimal('out_accuracy_meters', 12, 2)->nullable();
            $table->boolean('geofence_flagged')->default(false);
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->unsignedInteger('double_time_minutes')->nullable();
        });

        DB::statement('CREATE UNIQUE INDEX attendances_one_open_per_worker ON attendances (user_id) WHERE clocked_out_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX attendances_one_open_per_worker');

        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropColumn(['in_accuracy_meters', 'out_accuracy_meters', 'geofence_flagged', 'duration_minutes', 'double_time_minutes']);
        });
    }
};
