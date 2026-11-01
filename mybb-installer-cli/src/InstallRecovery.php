<?php
declare(strict_types=1);

namespace MyBB\Installer;

use FilesystemIterator;
use MyBB\Tooling\Manifest;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/** Shared, directly testable recovery operations for installer bootstrap writes. */
final class InstallRecovery
{
    public static function bootstrapJournalDigests(array $entry): array
    {
        $digests = [];
        foreach (array_merge([$entry['sha256'] ?? null], $entry['sha256_variants'] ?? []) as $digest) {
            if (is_string($digest) && preg_match('/^[a-f0-9]{64}$/D', $digest) && !in_array($digest, $digests, true)) {
                $digests[] = $digest;
            }
        }
        return $digests;
    }

    public static function removeBootstrapTransactionDirectories(string $target, array $relativePaths): array
    {
        $removed = [];
        $preserved = [];
        $prefix = 'inc/plugins/data/mybb-tooling/transactions/';
        $scan = static function (string $path) use (&$scan): bool {
            if (is_link($path)) return false;
            if (is_file($path)) return true;
            if (!is_dir($path)) return false;
            try {
                foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
                    if (!$scan($item->getPathname())) return false;
                }
            } catch (Throwable) { return false; }
            return true;
        };
        $erase = static function (string $path) use (&$erase): bool {
            if (is_file($path) && !is_link($path)) return @unlink($path);
            if (!is_dir($path) || is_link($path)) return false;
            try {
                foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
                    if (!$erase($item->getPathname())) return false;
                }
            } catch (Throwable) { return false; }
            return @rmdir($path);
        };

        foreach ($relativePaths as $relative) {
            try { Manifest::validateRelativePath((string) $relative); }
            catch (Throwable) { $preserved[] = (string) $relative; continue; }
            if (!is_string($relative) || !str_starts_with($relative, $prefix) || !preg_match('/^[a-z0-9][a-z0-9-]*-\d{14}-[a-f0-9]{6}$/D', basename($relative))) {
                $preserved[] = (string) $relative;
                continue;
            }
            $cursor = $target;
            $safe = true;
            foreach (explode('/', dirname($relative)) as $part) {
                if ($part === '.') continue;
                $cursor .= DIRECTORY_SEPARATOR . $part;
                if (is_link($cursor) || (file_exists($cursor) && !is_dir($cursor))) { $safe = false; break; }
            }
            if (!$safe) { $preserved[] = $relative; continue; }
            $path = $target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!file_exists($path) && !is_link($path)) continue;
            if (!is_dir($path) || is_link($path) || !$scan($path) || !$erase($path)) { $preserved[] = $relative; continue; }
            $removed[] = $relative;
        }
        return ['removed' => $removed, 'preserved' => $preserved];
    }

    /**
     * Remove only digest-matching bootstrap writes and journaled transaction trees.
     * The journal is persisted only after every owned artifact has been handled.
     */
    public static function removeBootstrapWritesForResume(string $target, string $journalPath, array &$journal, callable $persist): void
    {
        $preserved = [];
        $remove = [];
        foreach (array_reverse($journal['files']) as $entry) {
            if (!str_starts_with((string) ($entry['component'] ?? ''), 'bootstrap_')) continue;
            $relative = (string) ($entry['path'] ?? '');
            try { Manifest::validateRelativePath($relative); }
            catch (Throwable) { $preserved[] = $relative ?: '(invalid bootstrap journal path)'; continue; }
            $cursor = $target;
            $unsafe = false;
            $parts = explode('/', $relative);
            foreach ($parts as $i => $part) {
                $cursor .= DIRECTORY_SEPARATOR . $part;
                if (is_link($cursor) || ($i < count($parts) - 1 && !is_dir($cursor) && file_exists($cursor))) { $unsafe = true; break; }
            }
            if ($unsafe) { $preserved[] = $relative; continue; }
            if (!file_exists($cursor)) continue;
            $digest = (string) @hash_file('sha256', $cursor);
            if (!is_file($cursor) || !in_array($digest, self::bootstrapJournalDigests($entry), true)) { $preserved[] = $relative; continue; }
            $remove[] = [$relative, $cursor];
        }
        if ($preserved) throw new RuntimeException('Cannot safely resume bootstrap; changed or unsafe paths were preserved: ' . implode(', ', $preserved) . '. Roll back the install or inspect those paths.');

        foreach ($remove as [$relative, $path]) {
            if (!@unlink($path)) { $preserved[] = $relative; continue; }
            $dir = dirname($path);
            while ($dir !== $target && str_starts_with($dir, $target . DIRECTORY_SEPARATOR)) {
                if (!@rmdir($dir)) break;
                $dir = dirname($dir);
            }
        }
        if ($preserved) throw new RuntimeException('Cannot safely resume bootstrap; changed or unsafe paths were preserved: ' . implode(', ', $preserved) . '. Roll back the install or inspect those paths.');

        $hasTransactionJournal = array_key_exists('bootstrap_transactions', $journal);
        $cleanup = self::removeBootstrapTransactionDirectories($target, $journal['bootstrap_transactions'] ?? []);
        if ($cleanup['preserved']) throw new RuntimeException('Cannot safely resume bootstrap; transaction recovery data was preserved: ' . implode(', ', $cleanup['preserved']) . '. Review the installer journal.');
        $journal['bootstrap_transactions_removed'] = array_values(array_unique(array_merge($journal['bootstrap_transactions_removed'] ?? [], $cleanup['removed'])));
        if ($hasTransactionJournal) $journal['bootstrap_transactions'] = [];
        $journal['files'] = array_values(array_filter($journal['files'], static fn (array $entry): bool => !str_starts_with((string) ($entry['component'] ?? ''), 'bootstrap_')));
        $journal['status'] = 'applying';
        unset($journal['bootstrap']);
        $persist($journalPath, $journal);
    }
}
