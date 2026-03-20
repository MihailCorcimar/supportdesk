<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('tickets')
            ->where('status', 'resolved')
            ->update([
                'status' => 'closed',
                'closed_at' => DB::raw('COALESCE(closed_at, resolved_at, updated_at, created_at)'),
            ]);

        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE tickets MODIFY status ENUM('open','in_progress','pending','closed','cancelled') NOT NULL DEFAULT 'open'");
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE tickets DROP CONSTRAINT IF EXISTS tickets_status_check');
            DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_status_check CHECK (status IN ('open','in_progress','pending','closed','cancelled'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('tickets')
            ->where('status', 'cancelled')
            ->update(['status' => 'open']);

        DB::table('tickets')
            ->where('status', 'in_progress')
            ->update(['status' => 'pending']);

        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE tickets MODIFY status ENUM('open','pending','resolved','closed') NOT NULL DEFAULT 'open'");
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE tickets DROP CONSTRAINT IF EXISTS tickets_status_check');
            DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_status_check CHECK (status IN ('open','pending','resolved','closed'))");
        }
    }
};
