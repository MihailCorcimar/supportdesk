<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contact_functions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        $now = now();
        DB::table('contact_functions')->insert([
            ['name' => 'Direcao', 'slug' => 'direcao', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Financeiro', 'slug' => 'financeiro', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Compras', 'slug' => 'compras', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Operacoes', 'slug' => 'operacoes', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Tecnico', 'slug' => 'tecnico', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Recursos Humanos', 'slug' => 'recursos-humanos', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Comercial', 'slug' => 'comercial', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Outro', 'slug' => 'outro', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::create('contact_entity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entity_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['contact_id', 'entity_id']);
            $table->index(['entity_id', 'contact_id']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('function_id')->nullable()->after('user_id')->constrained('contact_functions')->nullOnDelete();
            $table->string('mobile_phone', 50)->nullable()->after('phone');
            $table->text('internal_notes')->nullable()->after('mobile_phone');
        });

        if (Schema::hasColumn('contacts', 'entity_id')) {
            $pairs = DB::table('contacts')
                ->select('id as contact_id', 'entity_id')
                ->whereNotNull('entity_id')
                ->get()
                ->map(fn (object $row) => [
                    'contact_id' => (int) $row->contact_id,
                    'entity_id' => (int) $row->entity_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all();

            if ($pairs !== []) {
                DB::table('contact_entity')->insert($pairs);
            }
        }

        if (Schema::hasColumn('contacts', 'job_title')) {
            $titles = DB::table('contacts')
                ->select('job_title')
                ->whereNotNull('job_title')
                ->where('job_title', '!=', '')
                ->distinct()
                ->pluck('job_title');

            foreach ($titles as $title) {
                $name = trim((string) $title);

                if ($name === '') {
                    continue;
                }

                $slugBase = Str::slug($name);
                $slugBase = $slugBase !== '' ? $slugBase : 'funcao';
                $slug = $slugBase;
                $suffix = 2;

                while (DB::table('contact_functions')->where('slug', $slug)->exists()) {
                    $slug = $slugBase.'-'.$suffix;
                    $suffix++;
                }

                $functionId = (int) DB::table('contact_functions')->insertGetId([
                    'name' => $name,
                    'slug' => $slug,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('contacts')
                    ->whereNull('function_id')
                    ->where('job_title', $title)
                    ->update(['function_id' => $functionId]);
            }
        }

        try {
            DB::statement('ALTER TABLE contacts DROP FOREIGN KEY contacts_entity_id_foreign');
        } catch (\Throwable) {
        }

        try {
            DB::statement('ALTER TABLE contacts DROP INDEX contacts_entity_id_email_unique');
        } catch (\Throwable) {
        }

        try {
            DB::statement('ALTER TABLE contacts DROP INDEX contacts_entity_id_is_active_index');
        } catch (\Throwable) {
        }

        Schema::table('contacts', function (Blueprint $table) {
            if (Schema::hasColumn('contacts', 'job_title')) {
                $table->dropColumn('job_title');
            }

            if (Schema::hasColumn('contacts', 'is_primary')) {
                $table->dropColumn('is_primary');
            }

            if (Schema::hasColumn('contacts', 'entity_id')) {
                $table->dropColumn('entity_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('entity_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->string('job_title')->nullable()->after('phone');
            $table->boolean('is_primary')->default(false)->after('job_title')->index();
        });

        $entityMap = DB::table('contact_entity')
            ->select('contact_id', DB::raw('MIN(entity_id) as entity_id'))
            ->groupBy('contact_id')
            ->get();

        foreach ($entityMap as $item) {
            DB::table('contacts')
                ->where('id', (int) $item->contact_id)
                ->update(['entity_id' => (int) $item->entity_id]);
        }

        $fallbackEntityId = DB::table('entities')->min('id');
        if ($fallbackEntityId !== null) {
            DB::table('contacts')
                ->whereNull('entity_id')
                ->update(['entity_id' => (int) $fallbackEntityId]);
        }

        $functions = DB::table('contact_functions')->pluck('name', 'id');
        DB::table('contacts')
            ->select('id', 'function_id')
            ->whereNotNull('function_id')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($functions): void {
                foreach ($rows as $row) {
                    $name = $functions[(int) $row->function_id] ?? null;
                    if ($name === null) {
                        continue;
                    }

                    DB::table('contacts')
                        ->where('id', (int) $row->id)
                        ->update(['job_title' => $name]);
                }
            });

        try {
            DB::statement('ALTER TABLE contacts ADD UNIQUE contacts_entity_id_email_unique (entity_id, email)');
        } catch (\Throwable) {
        }

        try {
            DB::statement('ALTER TABLE contacts ADD INDEX contacts_entity_id_is_active_index (entity_id, is_active)');
        } catch (\Throwable) {
        }

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropForeign(['function_id']);
            $table->dropColumn(['function_id', 'mobile_phone', 'internal_notes']);
        });

        Schema::dropIfExists('contact_entity');
        Schema::dropIfExists('contact_functions');
    }
};
