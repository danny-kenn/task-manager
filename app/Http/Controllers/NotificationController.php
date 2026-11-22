<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /** Cheap endpoint the browser polls every ~30 s (and the service worker calls on push). */
    public function poll(Request $request): JsonResponse
    {
        $u = $request->user();
        $items = $u->appNotifications()->with('actor')->latest('id')->limit(20)->get()->map->toPayload()->values();

        return response()->json(['unread' => $u->appNotifications()->whereNull('read_at')->count(), 'items' => $items])
            ->header('Cache-Control', 'no-store');
    }

    public function read(Request $request, int $id): JsonResponse
    {
        $request->user()->appNotifications()->whereKey($id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true, 'unread' => $request->user()->appNotifications()->whereNull('read_at')->count()]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->appNotifications()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true, 'unread' => 0]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:2000'],
            'keys.p256dh' => ['nullable', 'string', 'max:255'],
            'keys.auth' => ['nullable', 'string', 'max:255'],
        ]);
        PushSubscription::updateOrCreate(['endpoint_hash' => hash('sha256', $data['endpoint'])], [
            'user_id' => $request->user()->id, 'endpoint' => $data['endpoint'],
            'p256dh' => $data['keys']['p256dh'] ?? null, 'auth' => $data['keys']['auth'] ?? null,
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        return response()->json(['ok' => true, 'message' => 'Push notifications are on for this device.']);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);
        PushSubscription::where('user_id', $request->user()->id)->where('endpoint_hash', hash('sha256', $data['endpoint']))->delete();

        return response()->json(['ok' => true]);
    }
}
