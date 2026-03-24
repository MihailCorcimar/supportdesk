<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('users', 'is_special_operator') && ! Schema::hasColumn('users', 'is_admin')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->renameColumn('is_special_operator', 'is_admin');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'is_admin') && ! Schema::hasColumn('users', 'is_special_operator')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->renameColumn('is_admin', 'is_special_operator');
            });
        }
    }
};
