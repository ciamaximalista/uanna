<?php

namespace Oannes;

final class DateFormat
{
    private const MONTHS = [
        1 => 'enero',
        2 => 'febrero',
        3 => 'marzo',
        4 => 'abril',
        5 => 'mayo',
        6 => 'junio',
        7 => 'julio',
        8 => 'agosto',
        9 => 'septiembre',
        10 => 'octubre',
        11 => 'noviembre',
        12 => 'diciembre',
    ];

    public static function human(?string $date, string $timezone = 'Europe/Madrid'): string
    {
        if ($date === null || trim($date) === '') {
            return '';
        }

        try {
            $dt = new \DateTimeImmutable($date);
        } catch (\Throwable) {
            return $date;
        }

        try {
            $dt = $dt->setTimezone(new \DateTimeZone($timezone));
        } catch (\Throwable) {
        }

        $day = (int)$dt->format('j');
        $month = self::MONTHS[(int)$dt->format('n')] ?? $dt->format('m');

        return sprintf(
            '%d de %s de %s, %s horas',
            $day,
            $month,
            $dt->format('Y'),
            $dt->format('H:i')
        );
    }

    public static function day(?string $date, string $timezone = 'Europe/Madrid'): string
    {
        if ($date === null || trim($date) === '') {
            return '';
        }

        try {
            $dt = new \DateTimeImmutable($date);
        } catch (\Throwable) {
            return $date;
        }

        try {
            $dt = $dt->setTimezone(new \DateTimeZone($timezone));
        } catch (\Throwable) {
        }

        $month = self::MONTHS[(int)$dt->format('n')] ?? $dt->format('m');

        return sprintf('%d de %s de %s', (int)$dt->format('j'), $month, $dt->format('Y'));
    }

    public static function dayKey(?string $date, string $timezone = 'Europe/Madrid'): string
    {
        if ($date === null || trim($date) === '') {
            return '';
        }

        try {
            $dt = new \DateTimeImmutable($date);
        } catch (\Throwable) {
            return $date;
        }

        try {
            $dt = $dt->setTimezone(new \DateTimeZone($timezone));
        } catch (\Throwable) {
        }

        return $dt->format('Y-m-d');
    }
}
