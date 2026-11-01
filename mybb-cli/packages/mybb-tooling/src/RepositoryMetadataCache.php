<?php
declare(strict_types=1);

namespace MyBB\Tooling;

/** Bounds the Admin CP's public catalog metadata cache; cached values are display-only. */
final class RepositoryMetadataCache
{
    public const TTL_SECONDS = 300;
    private const MAX_AGE_SECONDS = 86400;
    private const MAX_ENTRIES = 50;
    private const MAX_BYTES = 4194304;

    public static function fresh(array $entries, string $key, int $now): ?array
    {
        $entry = $entries[$key] ?? null;
        if (!is_array($entry) || !is_int($entry['expires_at'] ?? null) || $entry['expires_at'] <= $now || !is_array($entry['data'] ?? null)) {
            return null;
        }
        return $entry['data'];
    }

    public static function lastFetchedAt(array $entries): ?int
    {
        $latest = null;
        foreach ($entries as $entry) {
            if (is_array($entry) && is_int($entry['fetched_at'] ?? null)) {
                $latest = max($latest ?? 0, $entry['fetched_at']);
            }
        }
        return $latest;
    }

    public static function store(array $entries, string $key, array $data, int $now): array
    {
        foreach ($entries as $entryKey => $entry) {
            if (!is_array($entry) || !is_int($entry['fetched_at'] ?? null) || $entry['fetched_at'] < $now - self::MAX_AGE_SECONDS) {
                unset($entries[$entryKey]);
            }
        }
        $entries[$key] = ['fetched_at' => $now, 'expires_at' => $now + self::TTL_SECONDS, 'data' => $data];
        uasort($entries, static fn(array $a, array $b): int => ($b['fetched_at'] ?? 0) <=> ($a['fetched_at'] ?? 0));
        $entries = array_slice($entries, 0, self::MAX_ENTRIES, true);
        while ($entries && strlen(serialize($entries)) > self::MAX_BYTES) {
            array_pop($entries);
        }
        return $entries;
    }
}
