<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materials_next', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->index();
            $table->string('unit', 20);
            $table->string('category', 100);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->index(['category', 'is_active']);
            $table->timestampsTz();
        });

        DB::table('materials')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $category = 'General';
                $sku = trim((string) $row->sku);
                $code = $sku !== '' ? $sku : 'MAT-'.substr(sha1($row->name.'|'.$row->unit.'|'.$category), 0, 10);
                $unit = mb_substr(trim((string) $row->unit), 0, 20);

                DB::table('materials_next')->insert([
                    'id' => $row->id,
                    'code' => $code,
                    'name' => mb_substr(trim((string) $row->name), 0, 255),
                    'unit' => $unit !== '' ? $unit : 'pcs',
                    'category' => $category,
                    'is_active' => true,
                    'notes' => $sku !== '' ? 'legacy_sku: '.$sku : null,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            }
        });

        Schema::table('material_list_items', function (Blueprint $table): void {
            $table->dropForeign(['material_id']);
        });

        Schema::drop('materials');
        Schema::rename('materials_next', 'materials');

        Schema::table('material_list_items', function (Blueprint $table): void {
            $table->foreign('material_id')->references('id')->on('materials')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT setval(pg_get_serial_sequence('materials', 'id'), GREATEST(COALESCE((SELECT MAX(id) FROM materials), 1), 1))");
        }
    }

    public function down(): void
    {
        Schema::create('materials_previous', function (Blueprint $table): void {
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

        DB::table('materials')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('materials_previous')->insert([
                    'id' => $row->id,
                    'sku' => $row->code,
                    'name' => $row->name,
                    'unit' => $row->unit,
                    'unit_cost' => 0,
                    'supplier' => null,
                    'stock' => 0,
                    'minimum_stock' => 0,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            }
        });

        Schema::table('material_list_items', function (Blueprint $table): void {
            $table->dropForeign(['material_id']);
        });

        Schema::drop('materials');
        Schema::rename('materials_previous', 'materials');

        Schema::table('material_list_items', function (Blueprint $table): void {
            $table->foreign('material_id')->references('id')->on('materials')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT setval(pg_get_serial_sequence('materials', 'id'), GREATEST(COALESCE((SELECT MAX(id) FROM materials), 1), 1))");
        }
    }
};
