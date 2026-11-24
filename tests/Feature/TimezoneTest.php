<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Support\Tz;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_times_are_shown_in_each_users_own_time_zone(): void
    {
        $user = User::factory()->create(['timezone' => 'Africa/Nairobi']);
        $task = Task::factory()->pending()->for($user, 'owner')->create(['created_at' => '2026-06-01 20:30:00']);

        // 20:30 UTC is 23:30 in Nairobi (UTC+3)...
        $this->actingAs($user)->get(route('tasks.show', $task))->assertOk()->assertSee('01 Jun 2026, 23:30');

        // ...and 16:30 in New York (UTC-4 in June). Same stored moment, different clock.
        $user->update(['timezone' => 'America/New_York']);
        $this->actingAs($user->fresh())->get(route('tasks.show', $task))->assertOk()->assertSee('01 Jun 2026, 16:30');
    }

    public function test_user_can_choose_a_time_zone_and_invalid_ones_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('profile.timezone'), ['timezone' => 'Asia/Tokyo'])->assertSessionHasNoErrors();
        $user->refresh();
        $this->assertSame('Asia/Tokyo', $user->timezone);
        $this->assertTrue($user->timezone_manual);

        $this->actingAs($user)->post(route('profile.timezone'), ['timezone' => 'Mars/Olympus'])->assertSessionHasErrors('timezone');
        $this->assertSame('Asia/Tokyo', $user->fresh()->timezone);
    }

    public function test_device_detection_never_overrides_a_manual_choice(): void
    {
        $manual = User::factory()->create(['timezone' => 'Asia/Tokyo', 'timezone_manual' => true]);
        $this->actingAs($manual)->postJson(route('profile.timezone'), ['timezone' => 'Africa/Nairobi', 'source' => 'device'])
            ->assertOk()->assertJson(['changed' => false]);
        $this->assertSame('Asia/Tokyo', $manual->fresh()->timezone);

        $auto = User::factory()->create();
        $this->actingAs($auto)->postJson(route('profile.timezone'), ['timezone' => 'Africa/Nairobi', 'source' => 'device'])
            ->assertOk()->assertJson(['changed' => true]);
        $this->assertSame('Africa/Nairobi', $auto->fresh()->timezone);
    }

    public function test_the_time_zone_is_remembered_at_sign_in(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.attempt'), ['email' => $user->email, 'password' => 'password', 'timezone' => 'Europe/London'])->assertRedirect();
        $this->assertSame('Europe/London', $user->fresh()->timezone);
    }

    public function test_today_follows_the_users_own_calendar_day(): void
    {
        $this->travelTo(Carbon::parse('2026-06-01 22:30:00', 'UTC')); // already 2 June in Nairobi, still 1 June in New York

        $nairobi = User::factory()->create(['timezone' => 'Africa/Nairobi']);
        $newYork = User::factory()->create(['timezone' => 'America/New_York']);

        $this->assertSame('2026-06-02', Tz::today($nairobi)->toDateString());
        $this->assertSame('2026-06-01', Tz::today($newYork)->toDateString());
    }
}
