<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'active' => EnsureUserIsActive::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Friendly JSON for AJAX calls (toggle, reorder, AI), friendly pages for everything else.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => match ($e->getStatusCode()) {
                        403 => 'You do not have permission to do that.',
                        404 => 'That item could not be found.',
                        419 => 'Your session expired. Please refresh the page.',
                        429 => 'Too many requests. Please slow down.',
                        default => $e->getMessage() ?: 'Something went wrong.',
                    },
                ], $e->getStatusCode());
            }
        });
    })->create();
