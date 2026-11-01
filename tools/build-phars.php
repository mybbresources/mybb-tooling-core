<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !extension_loaded('phar')) {
    fwrite(STDERR, "PHP CLI with the Phar extension is required.\n");
    exit(2);
}
$version = $argv[1] ?? '';
$output = $argv[2] ?? dirname(__DIR__).'/mybb-cli/dist';
$force = in_array('--force', $argv, true);
if (!preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/', $version)) {
    fwrite(STDERR, "Usage: php -d phar.readonly=0 tools/build-phars.php VERSION [OUTPUT_DIR] [--force]\n");
    exit(2);
}
if (ini_get('phar.readonly') !== '0') {
    fwrite(STDERR, "PHAR creation is disabled. Rerun PHP with -d phar.readonly=0.\n");
    exit(2);
}
$root = dirname(__DIR__);
require_once $root.'/autoload.php';
$bootstrapDirectory = getenv('MYBB_PHAR_BOOTSTRAP_DIR') ?: '';
$trustedKeysFile = getenv('MYBB_PHAR_TRUSTED_KEYS_FILE') ?: '';
$embeddedTrustedKeys = null;
$bootstrapAssets = [];
if ($bootstrapDirectory !== '' || $trustedKeysFile !== '') {
    if ($bootstrapDirectory === '' || $trustedKeysFile === '') {
        throw new RuntimeException('Embedding installer bootstrap assets requires both MYBB_PHAR_BOOTSTRAP_DIR and MYBB_PHAR_TRUSTED_KEYS_FILE.');
    }
    if (!extension_loaded('sodium') || !extension_loaded('zip')) throw new RuntimeException('Embedding signed bootstrap assets requires sodium and ZipArchive extensions.');
    $bootstrapDirectory = realpath($bootstrapDirectory) ?: throw new RuntimeException('Cannot resolve the signed bootstrap directory.');
    $trustedKeysFile = realpath($trustedKeysFile) ?: throw new RuntimeException('Cannot resolve the installer trusted-key map.');
    $trustedBytes = file_get_contents($trustedKeysFile);
    $embeddedTrustedKeys = json_decode((string)$trustedBytes, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($embeddedTrustedKeys) || !$embeddedTrustedKeys) throw new RuntimeException('Installer trusted-key map must contain at least one pinned public key.');
    $trustedBytes = json_encode($embeddedTrustedKeys, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    foreach (['bootstrap.json'=>'mybb-toolchain', 'repository.json'=>'mybbresources-repository'] as $manifestName=>$expectedSlug) {
        $archiveName = preg_replace('/\.json$/', '.zip', $manifestName);
        $manifestPath = $bootstrapDirectory.'/'.$manifestName;
        $archivePath = $bootstrapDirectory.'/'.$archiveName;
        if (is_link($manifestPath) || is_link($archivePath) || !is_file($manifestPath) || !is_file($archivePath)) {
            throw new RuntimeException('Signed installer bootstrap directory is incomplete or contains symlinks.');
        }
        $manifest = MyBB\Tooling\Manifest::decode((string)file_get_contents($manifestPath));
        MyBB\Tooling\Manifest::verifySignature($manifest, $embeddedTrustedKeys);
        if ($manifest['slug'] !== $expectedSlug || $manifest['version'] !== $version) throw new RuntimeException('Bootstrap manifest identity or version does not match the PHAR release.');
        if (filesize($archivePath) !== $manifest['package']['size'] || !hash_equals($manifest['package']['sha256'], hash_file('sha256', $archivePath))) {
            throw new RuntimeException('Bootstrap archive size or SHA-256 does not match its signed manifest.');
        }
        if (!extension_loaded('zip')) throw new RuntimeException('The ZipArchive extension is required to validate embedded bootstrap packages.');
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) throw new RuntimeException('Bootstrap package is not a readable ZIP archive.');
        if ($zip->numFiles > 10000) { $zip->close(); throw new RuntimeException('Bootstrap package exceeds the shared file-count limit.'); }
        $declared = array_fill_keys(array_column($manifest['files'], 'source'), true);
        $actual = [];
        $expandedBytes = 0;
        for ($index=0; $index<$zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat) || !isset($stat['name']) || str_ends_with($stat['name'], '/')) { $zip->close(); throw new RuntimeException('Bootstrap package contains an undeclared directory entry.'); }
            MyBB\Tooling\Manifest::validateArchiveEntryType($zip, $index, false);
            $entry = (string)$stat['name'];
            if (isset($actual[$entry]) || !isset($declared[$entry])) { $zip->close(); throw new RuntimeException('Bootstrap package entries must match its signed manifest exactly.'); }
            $expandedBytes += (int)($stat['size'] ?? 0);
            if ($expandedBytes > 67108864) { $zip->close(); throw new RuntimeException('Bootstrap package exceeds the shared expanded-byte limit.'); }
            $actual[$entry] = true;
        }
        $zip->close();
        if (count($actual) !== count($declared)) throw new RuntimeException('Bootstrap package is missing a manifest-declared file.');
        $bootstrapAssets['mybb-installer-cli/dist/'.$manifestName] = $manifestPath;
        $bootstrapAssets['mybb-installer-cli/dist/'.$archiveName] = $archivePath;
    }
    $bundleKeysPath = $bootstrapDirectory.'/trusted-keys.json';
    if (is_link($bundleKeysPath) || !is_file($bundleKeysPath)) throw new RuntimeException('Signed installer bootstrap directory is missing trusted-keys.json.');
    $bundleKeys = json_decode((string)file_get_contents($bundleKeysPath), true, 512, JSON_THROW_ON_ERROR);
    if ($bundleKeys !== $embeddedTrustedKeys) throw new RuntimeException('Embedded trust pins do not match the signed bundle trust map.');
    $bootstrapAssets['mybb-installer-cli/dist/trusted-keys.json'] = $bundleKeysPath;
}
if (!is_dir($output) && !mkdir($output, 0755, true) && !is_dir($output)) {
    throw new RuntimeException('Cannot create output directory.');
}
$output = realpath($output) ?: throw new RuntimeException('Cannot resolve output directory.');
$sourceEntries = [
    'autoload.php',
    'src',
    'mybb-cli/bin/mybb',
    'mybb-cli/config/trusted-keys.json',
    'mybb-installer-cli/bin/mybb-install',
    'mybb-installer-cli/src/InitialSetup.php',
    'mybb-installer-cli/src/InstallPlanOutput.php',
    'mybb-installer-cli/src/InstallRecovery.php',
    'mybb-installer-cli/src/InstallTrust.php',
    'mybb-installer-cli/config/trusted-keys.json',
];
$archives = [
    'mybb-cli.phar' => 'mybb-cli/bin/mybb',
    'mybb-install.phar' => 'mybb-installer-cli/bin/mybb-install',
];
foreach ($sourceEntries as $entry) {
    if (!file_exists($root.'/'.$entry)) throw new RuntimeException('Required PHAR source is missing: '.$entry);
}
foreach ($archives as $filename => $entrypoint) {
    $archivePath = $output.'/'.$filename;
    $checksumPath = $archivePath.'.sha256';
    if ((file_exists($archivePath) || is_link($archivePath) || file_exists($checksumPath) || is_link($checksumPath)) && !$force) {
        throw new RuntimeException('Refusing to overwrite '.$filename.'; pass --force to replace it.');
    }
    @unlink($archivePath);
    @unlink($checksumPath);
    try {
        $phar = new Phar($archivePath);
        $phar->startBuffering();
        foreach ($sourceEntries as $source) {
            $absolute = $root.'/'.$source;
            if (is_dir($absolute)) {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $item) {
                    if (!$item->isFile() || $item->isLink()) continue;
                    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($item->getPathname(), strlen($root)+1));
                    $phar->addFile($item->getPathname(), $relative);
                }
            } else {
                if ($embeddedTrustedKeys !== null && in_array($source, ['mybb-cli/config/trusted-keys.json', 'mybb-installer-cli/config/trusted-keys.json'], true)) {
                    $phar->addFromString($source, $trustedBytes);
                } else {
                    $phar->addFile($absolute, $source);
                }
            }
        }
        if ($filename === 'mybb-install.phar') {
            foreach ($bootstrapAssets as $archiveEntry=>$sourcePath) $phar->addFile($sourcePath, $archiveEntry);
        }
        $phar->setMetadata(['name' => $filename, 'version' => $version, 'format' => 'mybb-tooling-phar-v1']);
        $phar->setStub("#!/usr/bin/env php\n".$phar->createDefaultStub($entrypoint));
        $phar->setSignatureAlgorithm(Phar::SHA256);
        $phar->stopBuffering();
        if (!chmod($archivePath, 0755)) throw new RuntimeException('Cannot mark '.$filename.' executable.');
        $digest = hash_file('sha256', $archivePath);
        if (!is_string($digest) || file_put_contents($checksumPath, $digest.'  '.$filename."\n", LOCK_EX) === false) {
            throw new RuntimeException('Cannot write checksum for '.$filename.'.');
        }
        chmod($checksumPath, 0644);
        fwrite(STDOUT, $archivePath."\t".$digest."\n");
    } catch (Throwable $e) {
        @unlink($archivePath);
        @unlink($checksumPath);
        throw $e;
    }
}
