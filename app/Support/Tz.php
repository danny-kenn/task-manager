<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;

/**
 * Per-user time zones.
 *
 * Everything is STORED in UTC (config app.timezone). Only DISPLAY and "what day is it for me" logic use the viewer's
 * own zone, so a task finished at 8 pm in Nairobi reads 8 pm to the Nairobi user and 1 pm to someone in New York.
 * Due dates are plain calendar days and are never shifted.
 */
class Tz
{
    /** The zone to use for this person (their own, else the app default). */
    public static function name(?User $user = null): string
    {
        $user ??= auth()->user();
        $tz = $user?->timezone;

        return self::valid($tz) ? $tz : (string) config('app.timezone', 'UTC');
    }

    public static function valid(mixed $tz): bool
    {
        return is_string($tz) && $tz !== '' && in_array($tz, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
    }

    /** A stored (UTC) moment converted to the person's zone. Returns a copy; the original is untouched. */
    public static function local(mixed $date, ?User $user = null): ?Carbon
    {
        if ($date === null || $date === '') {
            return null;
        }
        $d = $date instanceof CarbonInterface ? Carbon::instance($date)->copy() : Carbon::parse($date);

        return $d->setTimezone(self::name($user));
    }

    /** The current moment in the person's zone. */
    public static function now(?User $user = null): Carbon
    {
        return Carbon::now(self::name($user));
    }

    /**
     * The person's current calendar day, as midnight in the app zone, so it compares directly with `due_date`
     * (a date column) and works in whereDate(). "Overdue" and "due today" therefore follow the viewer's own day.
     */
    public static function today(?User $user = null): Carbon
    {
        return Carbon::parse(self::now($user)->toDateString(), (string) config('app.timezone', 'UTC'))->startOfDay();
    }

    /** Zones for the profile dropdown: [region => [id => "City (UTC+03:00)"]]. */
    public static function options(): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $out = [];
        foreach (DateTimeZone::listIdentifiers() as $id) {
            $offset = (new DateTimeZone($id))->getOffset($now);
            $abs = abs($offset);
            $label = str_replace(['_', '/'], [' ', ' / '], $id).sprintf(' (UTC%s%02d:%02d)', $offset < 0 ? '-' : '+', intdiv($abs, 3600), intdiv($abs % 3600, 60));
            $region = str_contains($id, '/') ? explode('/', $id, 2)[0] : 'Other';
            $out[$region][$id] = $label;
        }

        return $out;
    }
}
