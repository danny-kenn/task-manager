<?php
// test_gemini.php — run from the project folder:   php test_gemini.php        (or:  php test_gemini.php --quick)
// Uses the SAME keys the app uses (GEMINI_API_KEYS in .env), tests DNS, TLS, every key and every model,
// and writes everything to storage/logs/ai-YYYY-MM-DD.log. Command-line only (it never runs from a browser).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__);
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$code = Illuminate\Support\Facades\Artisan::call('ai:check', in_array('--quick', $argv ?? [], true) ? ['--quick' => true] : [], new Symfony\Component\Console\Output\StreamOutput(fopen('php://stdout', 'w')));
exit($code);
