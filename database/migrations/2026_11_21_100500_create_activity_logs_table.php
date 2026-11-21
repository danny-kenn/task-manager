<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only audit trail. task_id is deliberately NOT a foreign key so history survives permanent deletion.
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('task_id')->nullable()->index();
            $table->string('action', 40)->index();               // created, updated, status_changed, deleted, restored, reordered ...
            $table->string('description', 255);
            $table->json('properties')->nullable();              // before/after diff
            $table->string('source', 10)->default('web');        // web | ai
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10);                          // user | assistant
            $table->text('content');
            $table->json('proposals')->nullable();               // action cards offered with this reply
            $table->string('model', 60)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('activity_logs');
    }
};
