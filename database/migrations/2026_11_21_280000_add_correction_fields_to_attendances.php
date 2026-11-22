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
            $table->foreignId('corrected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('correction_reason')->nullable();
        });

        $this->restoreOpenAttendanceIndex();
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('corrected_by');
            $table->dropColumn('correction_reason');
        });

        $this->restoreOpenAttendanceIndex();
    }

    /**
     * SQLite rebuilds the table on alter and drops the partial predicate,
     * leaving a unique index on every attendance of the worker.
     */
    private function restoreOpenAttendanceIndex(): void
    {
        DB::statement('DROP INDEX IF EXISTS attendances_one_open_per_worker');
        DB::statement('CREATE UNIQUE INDEX attendances_one_open_per_worker ON attendances (user_id) WHERE clocked_out_at IS NULL');
    }
};
