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
        Schema::create('ticket_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 100);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('ticket_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 100);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_initial')->default(false);
            $table->boolean('is_final')->default(false);
            $table->timestamps();
        });

        $now = now();

        DB::table('ticket_types')->insert([
            ['code' => 'question', 'name' => 'Question', 'sort_order' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'incident', 'name' => 'Incident', 'sort_order' => 20, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'request', 'name' => 'Request', 'sort_order' => 30, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'task', 'name' => 'Task', 'sort_order' => 40, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'other', 'name' => 'Other', 'sort_order' => 50, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('ticket_statuses')->insert([
            ['code' => 'open', 'name' => 'Open', 'sort_order' => 10, 'is_initial' => true, 'is_final' => false, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'in_progress', 'name' => 'In Progress', 'sort_order' => 20, 'is_initial' => true, 'is_final' => false, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'pending', 'name' => 'Pending', 'sort_order' => 30, 'is_initial' => true, 'is_final' => false, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'closed', 'name' => 'Closed', 'sort_order' => 40, 'is_initial' => false, 'is_final' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'cancelled', 'name' => 'Cancelled', 'sort_order' => 50, 'is_initial' => false, 'is_final' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        if (! Schema::hasColumn('tickets', 'creator_contact_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->foreignId('creator_contact_id')->nullable()->after('contact_id')->constrained('contacts')->nullOnDelete();
            });
        }

        DB::table('tickets')
            ->whereNull('creator_contact_id')
            ->whereNotNull('contact_id')
            ->update([
                'creator_contact_id' => DB::raw('contact_id'),
            ]);

        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `tickets` MODIFY `status` VARCHAR(32) NOT NULL');
            DB::statement('ALTER TABLE `tickets` MODIFY `type` VARCHAR(32) NOT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE tickets ALTER COLUMN status TYPE VARCHAR(32)');
            DB::statement('ALTER TABLE tickets ALTER COLUMN type TYPE VARCHAR(32)');
        }

        Schema::table('tickets', function (Blueprint $table) {
            $table->foreign('status', 'tickets_status_fk')
                ->references('code')
                ->on('ticket_statuses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreign('type', 'tickets_type_fk')
                ->references('code')
                ->on('ticket_types')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });

        if (! Schema::hasColumn('ticket_messages', 'body_format')) {
            Schema::table('ticket_messages', function (Blueprint $table) {
                $table->string('body_format', 16)->default('plain')->after('body');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('ticket_messages', 'body_format')) {
            Schema::table('ticket_messages', function (Blueprint $table) {
                $table->dropColumn('body_format');
            });
        }

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign('tickets_status_fk');
            $table->dropForeign('tickets_type_fk');
        });

        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `tickets` MODIFY `status` ENUM('open','in_progress','pending','closed','cancelled') NOT NULL DEFAULT 'open'");
            DB::statement("ALTER TABLE `tickets` MODIFY `type` ENUM('question','incident','request','task','other') NOT NULL DEFAULT 'request'");
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TABLE tickets ALTER COLUMN status TYPE VARCHAR(32)");
            DB::statement("ALTER TABLE tickets ALTER COLUMN type TYPE VARCHAR(32)");
        }

        if (Schema::hasColumn('tickets', 'creator_contact_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->dropConstrainedForeignId('creator_contact_id');
            });
        }

        Schema::dropIfExists('ticket_statuses');
        Schema::dropIfExists('ticket_types');
    }
};
