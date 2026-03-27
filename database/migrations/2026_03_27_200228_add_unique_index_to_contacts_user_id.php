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
        $duplicates = DB::table('contacts')
            ->select('user_id')
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('user_id');

        foreach ($duplicates as $userId) {
            $contactIds = DB::table('contacts')
                ->where('user_id', (int) $userId)
                ->orderBy('id')
                ->pluck('id');

            $idsToDetach = $contactIds->slice(1)->values()->all();
            if ($idsToDetach === []) {
                continue;
            }

            DB::table('contacts')
                ->whereIn('id', $idsToDetach)
                ->update(['user_id' => null]);
        }

        Schema::table('contacts', function (Blueprint $table) {
            $table->unique('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique('contacts_user_id_unique');
        });
    }
};
