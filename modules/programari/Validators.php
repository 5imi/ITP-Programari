<?php
declare(strict_types=1);

namespace Modules\Programari;

use Core\HttpException;

/**
 * Validatori de domeniu ITP (numar auto RO, telefon, zi lucratoare, slot orar, linie).
 */
final class Validators
{
    /**
     * Normalizeaza numarul auto (ex: "sv08lzb" -> "SV 08 LZB", key "SV08LZB").
     *
     * @return array{plate: string, plate_key: string}
     */
    public static function normalizePlate(string $raw): array
    {
        $compact = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $raw));
        if (strlen($compact) < 4 || strlen($compact) > 12) {
            throw new HttpException(422, 'INVALID_PLATE', 'Numărul de înmatriculare trebuie să conțină între 4 și 12 caractere alfanumerice.');
        }

        // Formatare standard RO: Judet (1-2 litere) + Cifre (2-3) + Litere (3) sau litere + cifre (nr. rosii)
        if (preg_match('/^(B|[A-Z]{2})(\d{2,3})([A-Z]{3})$/', $compact, $m) === 1) {
            $formatted = $m[1] . ' ' . $m[2] . ' ' . $m[3];
        } elseif (preg_match('/^(B|[A-Z]{2})(\d{4,6})$/', $compact, $m) === 1) {
            $formatted = $m[1] . ' ' . $m[2];
        } else {
            $formatted = trim((string) preg_replace('/\s+/', ' ', strtoupper($raw)));
        }

        return [
            'plate' => substr($formatted, 0, 24),
            'plate_key' => substr($compact, 0, 24),
        ];
    }

    public static function normalizePhone(string $raw): string
    {
        $trimmed = trim($raw);
        $digits = preg_replace('/[^0-9+]/', '', $trimmed) ?? '';
        $onlyDigits = preg_replace('/\D/', '', $digits) ?? '';

        if (strlen($onlyDigits) < 9 || strlen($onlyDigits) > 15) {
            throw new HttpException(422, 'INVALID_PHONE', 'Numărul de telefon este invalid (minim 9 cifre).');
        }
        return substr($trimmed, 0, 24);
    }

    public static function assertValidDate(string $iso): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso) !== 1) {
            throw new HttpException(422, 'INVALID_DATE', 'Data trebuie să fie în format YYYY-MM-DD.');
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $iso);
        if (!$dt || $dt->format('Y-m-d') !== $iso) {
            throw new HttpException(422, 'INVALID_DATE', 'Dată calendaristică invalidă.');
        }
        return $iso;
    }

    public static function isClosedDay(string $iso, array $stationConfig): bool
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $iso);
        if (!$dt) {
            return true;
        }
        // PHP N: 1 (Luni) .. 7 (Duminica)
        $dow = (int) $dt->format('N');
        $workDays = array_map('intval', (array) ($stationConfig['work_days'] ?? [1, 2, 3, 4, 5, 6]));
        return !in_array($dow, $workDays, true);
    }

    public static function assertValidSlot(string $time, array $validSlots): string
    {
        $time = trim($time);
        if (!in_array($time, $validSlots, true)) {
            throw new HttpException(422, 'INVALID_TIME_SLOT', 'Ora aleasă nu se află în intervalul orar al stației.');
        }
        return $time;
    }

    public static function assertValidLine(string $line, array $stationConfig): string
    {
        $line = trim($line);
        $lines = (array) ($stationConfig['lines'] ?? ['Linia 1', 'Linia 2', 'Linia 3']);
        if (!in_array($line, $lines, true)) {
            throw new HttpException(422, 'INVALID_LINE', 'Linia ITP selectată nu este validă.');
        }
        return $line;
    }

    public static function formatDateRo(string $iso): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) === 1) {
            return $m[3] . '.' . $m[2] . '.' . $m[1];
        }
        return $iso;
    }

    public static function addDaysIso(string $iso, int $days): string
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $iso);
        if (!$dt) {
            return $iso;
        }
        return $dt->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }
}
