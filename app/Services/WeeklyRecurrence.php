<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

class WeeklyRecurrence
{
    public const WINDOW_DAYS = 56;

    public const VALIDITY_DAYS = 730;

    public function window(string $from, string $until): array
    {
        $start = CarbonImmutable::parse($from, 'UTC')->startOfDay();
        $end = CarbonImmutable::parse($until, 'UTC')->startOfDay();
        $this->ensure($end->gte($start) && $start->diffInDays($end) < self::WINDOW_DAYS, 'window_until', 'La fenêtre est limitée à 56 jours inclusifs.');

        return [$start, $end];
    }

    public function ensure(bool $ok, string $field, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    public function active(array $rules, string $date): bool
    {
        if ($date < $rules['valid_from'] || $date > $rules['valid_until']) {
            return false;
        }
        $day = CarbonImmutable::parse($date, 'UTC');
        if (! in_array($day->dayOfWeekIso, $rules['weekdays'], true) || in_array($day->dayOfWeekIso, $rules['non_working_days'], true) || in_array($date, $rules['excluded_dates'], true)) {
            return false;
        }
        if ($rules['active_weeks'] !== [] && ! in_array($day->startOfWeek()->toDateString(), $rules['active_weeks'], true)) {
            return false;
        }
        foreach ($rules['holidays'] as $range) {
            if ($date >= $range['from'] && $date <= $range['until']) {
                return false;
            }
        }

        return true;
    }

    /** Refuse les heures sautées ou répétées, plutôt que choisir silencieusement un instant. */
    public function instant(string $date, string $time, string $timezone): CarbonImmutable
    {
        $local = $date.' '.$time;
        $tz = new DateTimeZone($timezone);
        $naive = CarbonImmutable::createFromFormat('!Y-m-d H:i', $local, 'UTC')->getTimestamp();
        $offsets = array_unique(array_column($tz->getTransitions($naive - 172800, $naive + 172800), 'offset'));
        $matches = [];
        foreach ($offsets as $offset) {
            $candidate = $naive - $offset;
            if ((new DateTimeImmutable('@'.$candidate))->setTimezone($tz)->format('Y-m-d H:i') === $local) {
                $matches[] = $candidate;
            }
        }
        $this->ensure(count($matches) === 1, 'start_time', 'Heure locale inexistante ou ambiguë le '.$date.' dans '.$timezone.'. Choisissez un autre horaire ou excluez cette date.');

        return CarbonImmutable::createFromTimestampUTC($matches[0]);
    }
}
