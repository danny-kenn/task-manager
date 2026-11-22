<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Many chats per user ("New chat" starts a fresh one; old ones stay in the sidebar).
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120)->default('New chat');
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });

        Schema::table('ai_messages', function (Blueprint $table) {
            $table->foreignId('conversation_id')->nullable()->after('user_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->json('blocks')->nullable();       // rich blocks shown under the reply: table | stats | report | choices | links
            $table->json('attachments')->nullable();  // [{path,mime,name,kind}] images / audio the user sent
            $table->json('followups')->nullable();    // suggested next questions
            $table->json('steps')->nullable();        // "thinking" steps shown as a collapsible trail
            $table->unsignedInteger('thinking_ms')->nullable();
        });

        // Per-user long-term memory (name, preferences, working style). Never shared between users.
        Schema::create('ai_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 30)->default('fact'); // name | preference | work | fact
            $table->string('content', 300);
            $table->timestamps();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_memories');
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('conversation_id');
            $table->dropColumn(['blocks', 'attachments', 'followups', 'steps', 'thinking_ms']);
        });
        Schema::dropIfExists('ai_conversations');
    }
};
