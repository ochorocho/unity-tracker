<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Provider;

/**
 * Typed access to decoded API responses, which are untrusted and may lack fields.
 */
final class Payload
{
    /**
     * @param array<mixed> $data
     */
    public static function string(array $data, string ...$path): string
    {
        $value = self::value($data, $path);
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function array(array $data, string ...$path): array
    {
        $value = self::value($data, $path);
        return is_array($value) ? $value : [];
    }

    /**
     * @param array<mixed> $data
     * @return list<array<mixed>>
     */
    public static function list(array $data, string ...$path): array
    {
        return array_values(array_filter(self::array($data, ...$path), is_array(...)));
    }

    /**
     * @param array<mixed> $data
     */
    public static function bool(array $data, string ...$path): bool
    {
        return self::value($data, $path) === true;
    }

    /**
     * @param array<mixed> $data
     */
    public static function int(array $data, string ...$path): int
    {
        $value = self::value($data, $path);
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int)$value : 0;
    }

    /**
     * Links end up in href attributes: only http(s) URLs are accepted.
     *
     * @param array<mixed> $data
     */
    public static function url(array $data, string ...$path): string
    {
        $url = self::string($data, ...$path);
        return preg_match('#^https?://#i', $url) === 1 ? $url : '';
    }

    /**
     * @param array<mixed> $data
     * @return \DateTimeImmutable in the server's time zone, the epoch if missing or invalid
     */
    public static function date(array $data, string ...$path): \DateTimeImmutable
    {
        try {
            $date = new \DateTimeImmutable(self::string($data, ...$path) ?: '@0');
        } catch (\Exception) {
            $date = new \DateTimeImmutable('@0');
        }
        return $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    /**
     * @param array<mixed> $data
     * @param array<string> $path
     */
    private static function value(array $data, array $path): mixed
    {
        $value = $data;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }
}
