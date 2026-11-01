<?php
declare(strict_types=1);
namespace MyBB\Tooling;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class OfficialMyBBArchive
{
    public static function locateRoot(string $stage): string
    {
        $root = realpath($stage);
        if ($root === false || !is_dir($root) || is_link($stage)) {
            throw new RuntimeException('Official archive staging directory is invalid.');
        }

        $candidates = [];
        $consider = static function (string $path) use (&$candidates): void {
            if (is_link($path) || !is_dir($path)) {
                return;
            }
            $installMarker = $path . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'index.php';
            $configMarker = $path . DIRECTORY_SEPARATOR . 'inc' . DIRECTORY_SEPARATOR . 'config.default.php';
            if (is_file($installMarker) && !is_link($installMarker)
                && is_file($configMarker) && !is_link($configMarker)) {
                $candidates[] = $path;
            }
        };

        $consider($root);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                $consider($entry->getPathname());
            }
        }

        if (count($candidates) !== 1) {
            throw new RuntimeException('Official archive does not contain exactly one recognizable MyBB root.');
        }
        return $candidates[0];
    }
}
