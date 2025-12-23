<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->ensureIdAutoIncrement('v2_invite_code');
        $this->ensureIdAutoIncrement('v2_log');
    }

    private function ensureIdAutoIncrement(string $table): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'id')) {
            return;
        }

        $dbName = DB::getDatabaseName();

        $column = DB::table('information_schema.COLUMNS')
            ->select(['EXTRA', 'IS_NULLABLE', 'COLUMN_TYPE'])
            ->where('TABLE_SCHEMA', $dbName)
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', 'id')
            ->first();

        if (!$column) {
            return;
        }

        $extra = strtolower((string) ($column->EXTRA ?? ''));
        $isNullable = strtoupper((string) ($column->IS_NULLABLE ?? '')) === 'YES';
        $needsAutoIncrement = !str_contains($extra, 'auto_increment');

        if (!$needsAutoIncrement && !$isNullable) {
            return;
        }

        $hasIndexOnId = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $dbName)
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', 'id')
            ->exists();

        if (!$hasIndexOnId) {
            $indexName = 'idx_' . $table . '_id';
            DB::statement(sprintf('ALTER TABLE `%s` ADD INDEX `%s` (`id`)', $table, $indexName));
        }

        $columnType = strtolower((string) ($column->COLUMN_TYPE ?? 'int'));
        $baseType = str_starts_with($columnType, 'bigint') ? 'BIGINT' : 'INT';
        $unsigned = str_contains($columnType, 'unsigned') ? ' UNSIGNED' : '';

        DB::statement(sprintf('ALTER TABLE `%s` MODIFY `id` %s%s NOT NULL AUTO_INCREMENT', $table, $baseType, $unsigned));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // no-op
    }
};

