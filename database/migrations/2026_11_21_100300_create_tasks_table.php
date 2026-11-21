<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();            // owner / creator
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('pending');                         // pending | completed
            $table->string('priority', 10)->default('medium');                        // low | medium | high | urgent
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('position')->default(0);                                  // drag-and-drop order (signed: new tasks go on top)

            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['assignee_id', 'status']);
            $table->index('status');
            $table->index('priority');
            $table->index('due_date');
            $table->index('position');
            $table->index('title');
        });

        Schema::create('task_tag', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['task_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_tag');
        Schema::dropIfExists('tasks');
    }
};
