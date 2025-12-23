<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_server_route')) {
            return;
        }

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `v2_server_route` MODIFY `action_value` TEXT NULL');
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "v2_server_route" ALTER COLUMN "action_value" TYPE TEXT');
            return;
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_server_route')) {
            return;
        }

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `v2_server_route` MODIFY `action_value` VARCHAR(255) NULL');
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "v2_server_route" ALTER COLUMN "action_value" TYPE VARCHAR(255)');
            return;
        }
    }
};

