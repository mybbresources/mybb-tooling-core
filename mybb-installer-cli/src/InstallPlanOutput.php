<?php
declare(strict_types=1);

namespace MyBB\Installer;

use InvalidArgumentException;

/** Renders secret-free installer plans to stderr without corrupting JSON stdout. */
final class InstallPlanOutput
{
    /** @param list<array{path:string,bytes:int}> $coreFiles @param list<string> $coreDirectories @param array<string,array{files:list<array{destination:string}>}> $packages */
    public static function freshInstallSections(array $coreFiles, array $coreDirectories, array $packages, bool $bootstrapReady, string $journalPath): array
    {
        $mappedCore = [];
        $directories = [];
        foreach ($coreFiles as $file) {
            if (!is_string($file['path'] ?? null) || !is_int($file['bytes'] ?? null)) throw new InvalidArgumentException('Core install plan contains an invalid file entry.');
            $mappedCore[] = $file['path'] . ' (' . $file['bytes'] . ' bytes)';
            self::addParentDirectories($directories, $file['path']);
        }
        foreach ($coreDirectories as $directory) {
            if (!is_string($directory)) throw new InvalidArgumentException('Core install plan contains an invalid directory entry.');
            $directories[$directory] = true;
            self::addParentDirectories($directories, $directory . '/placeholder');
        }

        $packageDestinations = [];
        if ($bootstrapReady) {
            foreach ($packages as $slug => $manifest) {
                if (!is_string($slug) || !is_array($manifest) || !is_array($manifest['files'] ?? null)) throw new InvalidArgumentException('Bootstrap package plan is invalid.');
                foreach ($manifest['files'] as $file) {
                    if (!is_string($file['destination'] ?? null)) throw new InvalidArgumentException('Bootstrap package contains an invalid destination.');
                    $packageDestinations[] = $slug . ': ' . $file['destination'];
                    self::addParentDirectories($directories, $file['destination']);
                }
            }
        }

        $setupOutputs = [
            'install/lock (official installer output)',
            'inc/config.php (official installer output)',
            'inc/settings.php (official installer output)',
        ];
        $setupPaths = ['install/lock', 'inc/config.php', 'inc/settings.php'];
        if ($bootstrapReady) {
            $setupOutputs[] = 'inc/plugins/data/mybb-tooling/installed.json (package ownership ledger)';
            $setupPaths[] = 'inc/plugins/data/mybb-tooling/installed.json';
            $packageDestinations[] = 'inc/plugins/data/mybb-tooling/transactions/ (temporary package transaction state)';
            $setupPaths[] = 'inc/plugins/data/mybb-tooling/transactions';
        }
        foreach ($setupPaths as $path) self::addParentDirectories($directories, $path);
        $directories = array_keys($directories);
        sort($directories, SORT_STRING);

        return [
            'MyBB core files' => $mappedCore,
            'Directories to create if absent' => $directories,
            'Official setup outputs' => $setupOutputs,
            'Signed companion package destinations' => $bootstrapReady ? $packageDestinations : ['none (core-only installation)'],
            'Installer recovery journal' => [$journalPath],
        ];
    }

    /** @param array<string, string|list<string>> $sections */
    public static function render(string $title, array $sections): string
    {
        self::assertPlainText($title);
        $lines = [$title];
        foreach ($sections as $label => $values) {
            self::assertPlainText($label);
            $lines[] = $label . ':';
            foreach (is_array($values) ? $values : [$values] as $value) {
                if (!is_string($value)) throw new InvalidArgumentException('Plan values must be strings.');
                self::assertPlainText($value);
                $lines[] = '  - ' . $value;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private static function assertPlainText(string $value): void
    {
        if ($value === '' || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Plan text must be non-empty plain text without control characters.');
        }
    }

    /** @param array<string,true> $directories */
    private static function addParentDirectories(array &$directories, string $path): void
    {
        $parent = dirname($path);
        while ($parent !== '.') {
            $directories[$parent] = true;
            $parent = dirname($parent);
        }
    }
}
