<?php
declare(strict_types=1);

/**
 * Keep standalone component repositories buildable while src/ remains the
 * canonical shared-tooling source in this workspace.
 */
$root = dirname(__DIR__);
$check = in_array('--check', $argv, true);
$targets = [
    $root . '/mybb-cli/packages/mybb-tooling',
    $root . '/mybb-installer-cli/packages/mybb-tooling',
];
$packageComposer = <<<'JSON'
{
  "name": "mybbresources/mybb-tooling",
  "description": "Shared secure package and catalog contracts for MyBB tooling",
  "type": "library",
  "license": "MIT",
  "require": {
    "php": ">=8.2",
    "ext-json": "*",
    "ext-sodium": "*",
    "ext-zip": "*"
  },
  "autoload": {
    "psr-4": {
      "MyBB\\Tooling\\": "src/"
    }
  }
}
JSON;
$canonical = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->isLink()) {
        throw new RuntimeException('Canonical shared source contains an unsupported filesystem entry.');
    }
    $relative = substr($file->getPathname(), strlen($root . '/src/') );
    $canonical['src/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative)] = file_get_contents($file->getPathname());
}
$canonical['LICENSE'] = file_get_contents($root . '/LICENSE');
$canonical['composer.json'] = $packageComposer . "\n";
ksort($canonical, SORT_STRING);

$failures = [];
foreach ($targets as $target) {
    if (is_link($target)) {
        throw new RuntimeException('Refusing a symlinked package snapshot directory.');
    }
    if (!$check && !is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
        throw new RuntimeException('Could not create a component package snapshot directory.');
    }
    $actual = [];
    if (is_dir($target)) {
        $snapshot = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));
        foreach ($snapshot as $file) {
            if (!$file->isFile() || $file->isLink()) {
                throw new RuntimeException('Component package snapshot contains an unsupported filesystem entry.');
            }
            $relative = substr($file->getPathname(), strlen($target) + 1);
            $actual[str_replace(DIRECTORY_SEPARATOR, '/', $relative)] = file_get_contents($file->getPathname());
        }
    }
    ksort($actual, SORT_STRING);
    if ($check) {
        if ($actual !== $canonical) $failures[] = basename(dirname($target)) . '/packages/mybb-tooling';
        continue;
    }
    foreach ($canonical as $relative => $contents) {
        $destination = $target . '/' . $relative;
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create a package snapshot directory.');
        }
        if (is_link($destination) || file_put_contents($destination, $contents, LOCK_EX) !== strlen($contents)) {
            throw new RuntimeException('Could not safely write a package snapshot file.');
        }
    }
    $expected = array_fill_keys(array_keys($canonical), true);
    foreach (array_keys($actual) as $relative) {
        if (!isset($expected[$relative])) $failures[] = basename(dirname($target)) . '/packages/mybb-tooling contains stale file ' . $relative;
    }
}

if ($failures) {
    fwrite(STDERR, "Shared package snapshots differ from canonical src/:\n - " . implode("\n - ", $failures) . "\nRun php tools/sync-shared-package.php to refresh them.\n");
    exit(1);
}
fwrite(STDOUT, $check ? "Shared package snapshots match canonical src/.\n" : "Shared package snapshots synchronized from canonical src/.\n");
