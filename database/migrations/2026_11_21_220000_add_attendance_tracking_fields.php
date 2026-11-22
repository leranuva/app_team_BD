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

        if ($this->usesGeneratedIndex()) {
            Schema::table('attendances', function (Blueprint $table): void {
                $table->unsignedBigInteger('open_worker_id')->nullable()
                    ->storedAs('CASE WHEN clocked_out_at IS NULL THEN user_id ELSE NULL END');
                $table->unique('open_worker_id', 'attendances_one_open_per_worker');
            });

            return;
        }

        DB::statement('CREATE UNIQUE INDEX attendances_one_open_per_worker ON attendances (user_id) WHERE clocked_out_at IS NULL');
    }

    public function down(): void
    {
        if ($this->usesGeneratedIndex()) {
            Schema::table('attendances', function (Blueprint $table): void {
                $table->dropUnique('attendances_one_open_per_worker');
                $table->dropColumn('open_worker_id');
            });
        } else {
            DB::statement('DROP INDEX attendances_one_open_per_worker');
        }

        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropColumn(['in_accuracy_meters', 'out_accuracy_meters', 'geofence_flagged', 'duration_minutes', 'double_time_minutes']);
        });
    }

    private function usesGeneratedIndex(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
