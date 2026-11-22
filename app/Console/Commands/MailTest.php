<?php

namespace App\Console\Commands;

use App\Mail\EventMail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/** php artisan mail:test you@gmail.com  : sends one real email using the .env mail settings and prints any error. */
class MailTest extends Command
{
    protected $signature = 'mail:test {to : Address to send the test email to}';
    protected $description = 'Send a test email to check the MAIL_* settings in .env';

    public function handle(): int
    {
        $this->line('Mailer: '.config('mail.default').'  Host: '.config('mail.mailers.'.config('mail.default').'.host').'  From: '.config('mail.from.address'));
        try {
            $user = User::where('email', $this->argument('to'))->first() ?? new User(['name' => 'there', 'email' => $this->argument('to')]);
            Mail::to($this->argument('to'))->send(new EventMail($user, 'Test email', 'If you can read this, email notifications work.', url('/')));
        } catch (\Throwable $e) {
            $this->error('FAILED: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info('Sent. Check the inbox (and spam) of '.$this->argument('to').'.');

        return self::SUCCESS;
    }
}
