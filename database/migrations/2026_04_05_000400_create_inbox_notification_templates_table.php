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
        Schema::create('inbox_notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inbox_id')->constrained('inboxes')->cascadeOnDelete();
            $table->string('event_key', 120);
            $table->string('subject_template')->nullable();
            $table->string('title_template')->nullable();
            $table->text('body_template')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['inbox_id', 'event_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inbox_notification_templates');
    }
};

