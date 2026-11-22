<?php

namespace App\Console\Commands;

use App\Services\WebPush;
use Illuminate\Console\Command;

class WebPushKeys extends Command
{
    protected $signature = 'webpush:keys {--force : replace existing keys}';

    protected $description = 'Generate VAPID keys for browser push notifications and store them in .env';

    public function handle(): int
    {
        $env = base_path('.env');
        $text = is_file($env) ? file_get_contents($env) : '';
        if (str_contains($text, 'VAPID_PUBLIC_KEY=') && preg_match('/^VAPID_PUBLIC_KEY=.+$/m', $text) && ! $this->option('force')) {
            $this->info('VAPID keys already exist. Use --force to replace them (existing browser subscriptions would stop working).');

            return self::SUCCESS;
        }
        $k = WebPush::generateKeys();
        $text = preg_replace('/^VAPID_(PUBLIC|PRIVATE)_KEY=.*\R?/m', '', $text);
        $text = rtrim($text)."\n\nVAPID_PUBLIC_KEY={$k['public']}\nVAPID_PRIVATE_KEY={$k['private']}\n";
        file_put_contents($env, $text);
        $this->info('VAPID keys written to .env. Run: php artisan config:clear');

        return self::SUCCESS;
    }
}
