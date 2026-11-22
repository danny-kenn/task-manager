<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each person keeps their OWN drag-and-drop order; reordering never moves anyone else's list.
        if (! Schema::hasTable('task_orders')) {
            Schema::create('task_orders', function (Blueprint $table) {
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('task_id')->constrained()->cascadeOnDelete();
                $table->integer('position')->default(0);
                $table->primary(['user_id', 'task_id']);
            });
        }

        // Self-registered accounts wait here until a manager/admin approves them.
        if (! Schema::hasColumn('users', 'approved_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('approved_at')->nullable()->after('is_active');
            });
            DB::table('users')->update(['approved_at' => DB::raw('created_at')]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'approved_at')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('approved_at'));
        }
        Schema::dropIfExists('task_orders');
    }
};
