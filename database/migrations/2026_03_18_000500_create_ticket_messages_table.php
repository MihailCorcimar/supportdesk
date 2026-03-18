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
        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->enum('author_type', ['user', 'contact', 'system'])->default('user');
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('author_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->longText('body');
            $table->json('attachments')->nullable();
            $table->boolean('is_internal')->default(false)->index();
            $table->timestamps();

            $table->index(['ticket_id', 'created_at']);
            $table->index(['author_type', 'author_user_id']);
            $table->index(['author_type', 'author_contact_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_messages');
    }
};
