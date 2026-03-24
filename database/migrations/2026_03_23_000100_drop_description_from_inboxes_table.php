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
        if (Schema::hasColumn('inboxes', 'description')) {
            Schema::table('inboxes', function (Blueprint $table): void {
                $table->dropColumn('description');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('inboxes', 'description')) {
            Schema::table('inboxes', function (Blueprint $table): void {
                $table->text('description')->nullable()->after('slug');
            });
        }
    }
};
