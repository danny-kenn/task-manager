<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PwaController extends Controller
{
    public function manifest(): JsonResponse
    {
        $name = config('app.name');

        return response()->json([
            'name' => $name, 'short_name' => 'Tasks', 'description' => 'Tasks, teamwork and an AI assistant that does the clicking for you.',
            'id' => '/', 'start_url' => '/?source=pwa', 'scope' => '/', 'display' => 'standalone', 'orientation' => 'portrait-primary',
            'background_color' => '#0d1117', 'theme_color' => '#0d1117', 'lang' => 'en', 'categories' => ['productivity', 'business'],
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                ['name' => 'My tasks', 'url' => '/tasks?assignee=me&status=pending', 'icons' => [['src' => '/icons/icon-192.png', 'sizes' => '192x192']]],
                ['name' => 'New task', 'url' => '/tasks/create', 'icons' => [['src' => '/icons/icon-192.png', 'sizes' => '192x192']]],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function offline(): Response
    {
        return response(view('pwa.offline'), 200, ['Cache-Control' => 'public, max-age=600']);
    }
}
