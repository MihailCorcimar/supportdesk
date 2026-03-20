<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inbox_user', function (Blueprint $table): void {
            $table->boolean('can_manage_users')->default(false)->after('user_id');
        });

        DB::table('inbox_user')->update(['can_manage_users' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inbox_user', function (Blueprint $table): void {
            $table->dropColumn('can_manage_users');
        });
    }
};
