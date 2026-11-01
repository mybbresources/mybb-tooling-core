<?php
declare(strict_types=1);
namespace MyBB\Installer;

use RuntimeException;

final class InstallTrust
{
    /** Ensure the pinned trust map and the adjacent bundle map are distinct readable files. */
    public static function assertSeparate(string $trustedPath, string $bundlePath): void
    {
        if (!is_file($trustedPath) || !is_file($bundlePath) || is_link($trustedPath) || is_link($bundlePath)) {
            throw new RuntimeException('Installer trust keys and bootstrap trust map must be readable regular files.');
        }
        $trustedIdentity = self::identity($trustedPath);
        $bundleIdentity = self::identity($bundlePath);
        if ($trustedIdentity === null || $bundleIdentity === null || hash_equals($trustedIdentity, $bundleIdentity)) {
            throw new RuntimeException('Installer trust keys must be pinned separately from the downloadable bootstrap assets');
        }
    }

    /** Copy a PHAR-contained ZIP to private disk because ZipArchive needs a real file path. */
    public static function stageEmbeddedPackageArchive(string $source, string $directory, string $filename, array $manifest): string
    {
        if (!str_starts_with($source, 'phar://')) return $source;
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,62}\.zip$/D', $filename)) throw new RuntimeException('Invalid embedded package staging filename.');
        if (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory)) throw new RuntimeException('Could not create private bootstrap staging directory.');
        if (is_link($directory) || (fileperms($directory) & 0077) !== 0) throw new RuntimeException('Bootstrap staging directory must be private and cannot be a symlink.');
        $target = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;
        if (file_exists($target) || is_link($target)) throw new RuntimeException('Bootstrap staging destination already exists.');
        $input = @fopen($source, 'rb');
        $output = @fopen($target, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            @unlink($target);
            throw new RuntimeException('Could not stage embedded bootstrap package.');
        }
        @chmod($target, 0600);
        try {
            $copied = stream_copy_to_stream($input, $output);
            if ($copied === false || !fflush($output)) throw new RuntimeException('Could not finish staging embedded bootstrap package.');
        } catch (\Throwable $error) {
            fclose($input);
            fclose($output);
            @unlink($target);
            throw $error;
        }
        fclose($input);
        fclose($output);
        $expectedSize = $manifest['package']['size'] ?? null;
        $expectedDigest = $manifest['package']['sha256'] ?? null;
        if (!is_int($expectedSize) || !is_string($expectedDigest) || filesize($target) !== $expectedSize || !hash_equals($expectedDigest, (string)hash_file('sha256', $target))) {
            @unlink($target);
            throw new RuntimeException('Staged bootstrap package does not match its signed size and digest.');
        }
        return $target;
    }

    private static function identity(string $path): ?string
    {
        $resolved = realpath($path);
        if ($resolved !== false) return 'file:'.$resolved;
        if (!str_starts_with($path, 'phar://')) return null;
        $uriPath = str_replace('\\', '/', substr($path, strlen('phar://')));
        $archiveMarker = stripos($uriPath, '.phar/');
        if ($archiveMarker === false) return null;
        $archivePath = substr($uriPath, 0, $archiveMarker + strlen('.phar'));
        $entryPath = substr($uriPath, $archiveMarker + strlen('.phar/'));
        $archive = realpath($archivePath);
        if ($archive === false || $entryPath === '') return null;
        return 'phar:'.$archive.'/'.$entryPath;
    }
}
