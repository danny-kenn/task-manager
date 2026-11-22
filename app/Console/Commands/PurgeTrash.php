<?php

namespace App\Console\Commands;

use App\Models\Task;
use Illuminate\Console\Command;

/** Daily: tasks deleted more than 30 days ago are removed for good. */
class PurgeTrash extends Command
{
    protected $signature = 'tasks:purge-trash';
    protected $description = 'Permanently remove tasks that have been in the trash for 30+ days';

    public function handle(): int
    {
        $n = 0;
        Task::onlyTrashed()->where('deleted_at', '<', now()->subDays(30))->chunkById(100, function ($tasks) use (&$n) {
            foreach ($tasks as $t) {
                $t->forceDelete();
                $n++;
            }
        });
        $this->info("Purged {$n} tasks.");

        return self::SUCCESS;
    }
}
