<?php
declare(strict_types=1);
namespace MyBB\Tooling;

/** Reads the installed plugin files and MyBB's generated activation cache without executing either. */
final class PluginInventory
{
    /** @return array{plugins:list<array{slug:string,file:string,version:?string,active:?bool,managed:bool}>,activation_cache:string} */
    public static function inspect(string $siteRoot, array $ledger): array
    {
        $root = realpath($siteRoot);
        if ($root === false) throw new \RuntimeException('Cannot resolve MyBB site root.');
        $active = self::readActivePlugins($root . '/cache/plugins.php');
        $plugins = [];
        $seen = [];
        foreach (glob($root . '/inc/plugins/*.php') ?: [] as $filePath) {
            if (is_link($filePath) || !is_file($filePath)) continue;
            $file = basename($filePath);
            if ($file === 'index.html') continue;
            $name = substr($file, 0, -4);
            $record = null;
            foreach ($ledger as $slug => $entry) {
                foreach (($entry['files'] ?? []) as $ownedPath => $metadata) {
                    if ($ownedPath === 'inc/plugins/' . $file && ($metadata['role'] ?? '') === 'plugin') {
                        $record = ['slug' => (string)$slug, 'version' => isset($entry['version']) ? (string)$entry['version'] : null];
                        break 2;
                    }
                }
            }
            $slug = $record['slug'] ?? $name;
            if (isset($seen[$slug])) continue;
            $seen[$slug] = true;
            $plugins[] = ['slug' => $slug, 'file' => $file, 'version' => $record['version'] ?? null,
                'active' => $active === null ? null : in_array($name, $active, true), 'managed' => $record !== null];
        }
        usort($plugins, static fn(array $a, array $b): int => strcmp($a['slug'], $b['slug']));
        return ['plugins' => $plugins, 'activation_cache' => $active === null ? 'unavailable' : 'available'];
    }

    /** A genuinely absent initial cache is safe to initialize on an explicit activation; malformed caches remain unavailable. */
    public static function activationCacheIsMissing(string $siteRoot): bool
    {
        $root = realpath($siteRoot);
        if ($root === false) return false;
        $cacheDirectory = $root . '/cache';
        if (is_link($cacheDirectory) || !is_dir($cacheDirectory)) return false;
        $path = $cacheDirectory . '/plugins.php';
        return !file_exists($path) && !is_link($path);
    }

    /** @return list<string>|null null means unavailable or not safely parseable. */
    private static function readActivePlugins(string $path): ?array
    {
        if (!is_file($path) || is_link($path) || !is_readable($path)) return null;
        $source = file_get_contents($path);
        if (!is_string($source) || strlen($source) > 2_000_000) return null;
        // MyBB writes this generated cache using var_export. Only accept a literal string list;
        // never include/eval the PHP cache file because it is derived from site-controlled data.
        if (!preg_match("/'active'\\s*=>\\s*array\\s*\\((.*?)\\)\\s*,/s", $source, $match)) return null;
        $body = $match[1];
        if (preg_match('/[^\s,0-9=>()\'"\\\\A-Za-z_.-]/', $body)) return null;
        preg_match_all("/=>\\s*'([a-zA-Z0-9_-]+)'|=>\\s*\"([a-zA-Z0-9_-]+)\"/", $body, $items, PREG_SET_ORDER);
        $plugins = [];
        foreach ($items as $item) {
            $value = $item[1] !== '' ? $item[1] : ($item[2] ?? '');
            if ($value !== '') $plugins[] = $value;
        }
        return $plugins;
    }
}
