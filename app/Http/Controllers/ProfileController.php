<?php

namespace App\Http\Controllers;

use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    private const DIR = 'uploads/avatars';

    public function edit(Request $request)
    {
        $user = $request->user();
        $stats = [
            'owned' => $user->tasks()->count(),
            'assigned' => $user->assignedTasks()->count(),
            'done' => $user->assignedTasks()->where('status', 'completed')->count(),
        ];

        return view('profile.edit', ['user' => $user, 'stats' => $stats, 'events' => Notifier::eventsFor($user), 'channels' => Notifier::CHANNELS, 'pushReady' => (bool) config('services.webpush.public_key')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'avatar_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $user->update($data);

        return back()->with('success', 'Profile updated.');
    }

    public function password(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers(), 'different:current_password'],
        ], [
            'current_password.current_password' => 'Your current password is not correct.',
            'password.different' => 'The new password must be different from the current one.',
        ]);
        $request->user()->update(['password' => $request->password]);

        return back()->with('success', 'Password changed.');
    }

    /** Profile photo: re-encoded server side as a 512px JPEG (strips metadata, kills disguised files). */
    public function avatar(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate(['avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:6144']], ['avatar.image' => 'Please choose a picture (JPG, PNG, WebP or GIF).', 'avatar.max' => 'The picture must be under 6 MB.']);
        $user = $request->user();
        $file = $request->file('avatar');

        if (! is_dir(public_path(self::DIR))) {
            mkdir(public_path(self::DIR), 0775, true);
        }
        $this->deleteOld($user);
        $base = self::DIR.'/u'.$user->id.'-'.bin2hex(random_bytes(5));

        if (function_exists('imagecreatefromstring') && function_exists('imagecreatetruecolor')) {
            $src = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
            if (! $src) {
                return $this->avatarResult($request, false, 'That picture could not be read.');
            }
            $w = imagesx($src);
            $h = imagesy($src);
            $side = min($w, $h);
            $dst = imagecreatetruecolor(512, 512);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), 512, 512, $side, $side);
            $rel = $base.'.jpg';
            imagejpeg($dst, public_path($rel), 86);
        } else {
            // PHP's GD extension is off (enable "extension=gd" in php.ini for resizing). The picture was already
            // validated as a real image and the browser crops/resizes it before upload, so store it as-is.
            $ext = in_array($file->guessExtension(), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) ? $file->guessExtension() : 'jpg';
            $rel = $base.'.'.$ext;
            $file->move(public_path(self::DIR), basename($rel));
        }
        $user->update(['avatar_path' => $rel]);

        return $this->avatarResult($request, true, 'Profile photo updated.', $user->fresh()->avatar_url);
    }

    public function removeAvatar(Request $request): JsonResponse|RedirectResponse
    {
        $this->deleteOld($request->user());
        $request->user()->update(['avatar_path' => null]);

        return $this->avatarResult($request, true, 'Profile photo removed.');
    }

    public function notifications(Request $request): JsonResponse|RedirectResponse
    {
        $in = (array) $request->input('prefs', []);
        $prefs = (array) $request->user()->notify_prefs;
        foreach (Notifier::eventsFor($request->user()) as $event => $_) {
            foreach (Notifier::CHANNELS as $ch => $__) {
                $prefs[$event][$ch] = ! empty($in[$event][$ch]); // unchecked boxes are simply absent from the form
            }
        }
        $request->user()->update(['notify_prefs' => $prefs]);

        return $request->expectsJson() ? response()->json(['ok' => true, 'message' => 'Notification settings saved.']) : back()->with('success', 'Notification settings saved.');
    }

    private function deleteOld($user): void
    {
        if ($user->avatar_path && str_starts_with($user->avatar_path, self::DIR) && is_file(public_path($user->avatar_path))) {
            @unlink(public_path($user->avatar_path));
        }
    }

    private function avatarResult(Request $request, bool $ok, string $msg, ?string $url = null): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => $ok, 'message' => $msg, 'url' => $url], $ok ? 200 : 422);
        }

        return back()->with($ok ? 'success' : 'error', $msg);
    }

    /** The welcome tour was finished or dismissed for good. */
    public function tourDone(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->user()->forceFill(['onboarded_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    /** "Replay the tour" from the profile page. */
    public function tourReset(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->user()->forceFill(['onboarded_at' => null])->save();

        return response()->json(['ok' => true]);
    }
}
