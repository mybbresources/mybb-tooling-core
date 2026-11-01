<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/autoload.php';

if (!extension_loaded('sodium') || !extension_loaded('zip') || !extension_loaded('phar')) {
    throw new RuntimeException('The PHAR bootstrap smoke test requires sodium, ZipArchive, and Phar.');
}

$root = dirname(__DIR__);
$temporary = sys_get_temp_dir().'/mybb-phar-bootstrap-'.bin2hex(random_bytes(6));
if (!mkdir($temporary, 0700)) throw new RuntimeException('Could not create PHAR bootstrap test directory.');
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    @rmdir($path);
};
$run = static function (array $command, ?array $overrides = null): array {
    $pipes = [];
    $environment = $overrides === null ? null : array_merge(getenv(), $overrides);
    $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, null, $environment);
    if (!is_resource($process)) throw new RuntimeException('Could not start bootstrap test command.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};

try {
    $version = '7.6.5';
    $keyId = 'bootstrap-smoke-key';
    $keypair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keypair);
    $publicKey = sodium_crypto_sign_publickey($keypair);
    $bootstrapDirectory = $temporary.'/bootstrap';
    $trustedKeysPath = $temporary.'/trusted-keys.json';
    $secretKeyPath = $temporary.'/signing-secret';
    $outputDirectory = $temporary.'/dist';
    mkdir($bootstrapDirectory, 0700);
    mkdir($outputDirectory, 0700);
    $trustedKeys = [$keyId=>['public_key'=>base64_encode($publicKey)]];
    file_put_contents($trustedKeysPath, json_encode($trustedKeys, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n", LOCK_EX);
    file_put_contents($secretKeyPath, base64_encode($secretKey)."\n", LOCK_EX);
    chmod($trustedKeysPath, 0600);
    chmod($secretKeyPath, 0600);

    [$code, $stdout, $stderr] = $run([PHP_BINARY, $root.'/tools/build-bootstrap.php', $version, $keyId, $secretKeyPath, $trustedKeysPath, $bootstrapDirectory]);
    if ($code !== 0) throw new RuntimeException('Signed bootstrap fixture build failed: '.$stdout.$stderr);
    $before = [
        'cli'=>file_get_contents($root.'/mybb-cli/config/trusted-keys.json'),
        'installer'=>file_get_contents($root.'/mybb-installer-cli/config/trusted-keys.json'),
    ];
    $environment = [
        'MYBB_PHAR_BOOTSTRAP_DIR'=>$bootstrapDirectory,
        'MYBB_PHAR_TRUSTED_KEYS_FILE'=>$trustedKeysPath,
    ];
    [$code, $stdout, $stderr] = $run([PHP_BINARY, '-d', 'phar.readonly=0', $root.'/tools/build-phars.php', $version, $outputDirectory], $environment);
    if ($code !== 0) throw new RuntimeException('PHAR build with signed bootstrap assets failed: '.$stdout.$stderr);
    [$code, $stdout, $stderr] = $run([PHP_BINARY, $root.'/tests/phar-smoke.php', $version, $outputDirectory]);
    if ($code !== 0) throw new RuntimeException('Embedded bootstrap PHAR execution failed: '.$stdout.$stderr);

    $installer = new Phar($outputDirectory.'/mybb-install.phar');
    require_once $root.'/mybb-installer-cli/src/InstallTrust.php';
    foreach (['bootstrap.json','bootstrap.zip','repository.json','repository.zip','trusted-keys.json'] as $asset) {
        $entry = 'mybb-installer-cli/dist/'.$asset;
        if (!$installer->offsetExists($entry)) throw new RuntimeException('Installer PHAR is missing embedded asset '.$asset);
        $sourcePath = $bootstrapDirectory.'/'.$asset;
        if (!hash_equals(hash_file('sha256', $sourcePath), hash('sha256', $installer[$entry]->getContent()))) {
            throw new RuntimeException('Embedded bootstrap asset differs from its verified build input: '.$asset);
        }
    }
    foreach (['mybb-cli/config/trusted-keys.json','mybb-installer-cli/config/trusted-keys.json'] as $path) {
        $embedded = json_decode($installer[$path]->getContent(), true, 512, JSON_THROW_ON_ERROR);
        if ($embedded !== $trustedKeys) throw new RuntimeException('Installer PHAR does not contain the pinned trust map at '.$path);
    }
    $installerUri = 'phar://'.$outputDirectory.'/mybb-install.phar/';
    $bootstrapPaths = array_map(static fn(string $name): string => $installerUri.'mybb-installer-cli/dist/'.$name, ['bootstrap.json','bootstrap.zip','repository.json','repository.zip','trusted-keys.json']);
    if (count(array_filter($bootstrapPaths, 'is_file')) !== 5) throw new RuntimeException('Installer PHAR cannot discover its embedded bundle through the default bootstrap directory.');
    MyBB\Installer\InstallTrust::assertSeparate($installerUri.'mybb-installer-cli/config/trusted-keys.json', $installerUri.'mybb-installer-cli/dist/trusted-keys.json');
    try {
        MyBB\Installer\InstallTrust::assertSeparate($installerUri.'mybb-installer-cli/config/trusted-keys.json', $installerUri.'mybb-installer-cli/config/trusted-keys.json');
        throw new RuntimeException('Installer accepted its bootstrap trust map as the pinned key map.');
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'pinned separately')) throw $error;
    }
    $site = $temporary.'/embedded-install-site';
    mkdir($site.'/inc', 0755, true);
    file_put_contents($site.'/inc/config.php', '<?php');
    file_put_contents($site.'/inc/config.default.php', '<?php');
    file_put_contents($site.'/inc/class_core.php', '$version = "1.8.41";');
    file_put_contents($site.'/global.php', '<?php');
    $packageManager = new MyBB\Tooling\PackageManager($site, $site.'/inc/plugins/data/mybb-tooling', $trustedKeys);
    $bootstrapStage = $temporary.'/embedded-bootstrap-stage';
    foreach ([['bootstrap','mybb-toolchain'],['repository','mybbresources-repository']] as [$prefix,$slug]) {
        $manifestPath = $installerUri.'mybb-installer-cli/dist/'.$prefix.'.json';
        $packagePath = $installerUri.'mybb-installer-cli/dist/'.$prefix.'.zip';
        $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['slug'] ?? null) !== $slug) throw new RuntimeException('Embedded bootstrap has the wrong package identity.');
        $stagedPackage = MyBB\Installer\InstallTrust::stageEmbeddedPackageArchive($packagePath, $bootstrapStage, $slug.'.zip', $manifest);
        $packageManager->installBootstrap($stagedPackage, $manifest);
    }
    if (!is_file($site.'/inc/plugins/data/mybb-tooling/bin/mybb') || !is_file($site.'/inc/plugins/mybbresources_repository.php')) {
        throw new RuntimeException('The embedded installer bundles could not bootstrap the CLI and repository plugin.');
    }
    $cli = new Phar($outputDirectory.'/mybb-cli.phar');
    if (json_decode($cli['mybb-cli/config/trusted-keys.json']->getContent(), true, 512, JSON_THROW_ON_ERROR) !== $trustedKeys) {
        throw new RuntimeException('CLI PHAR does not contain the same pinned trust map.');
    }
    foreach ([$outputDirectory.'/mybb-install.phar', $outputDirectory.'/mybb-cli.phar'] as $pharPath) {
        if (str_contains((string)file_get_contents($pharPath), base64_encode($secretKey))) throw new RuntimeException('A PHAR unexpectedly contains the private signing key.');
    }
    if ($before['cli'] !== file_get_contents($root.'/mybb-cli/config/trusted-keys.json') || $before['installer'] !== file_get_contents($root.'/mybb-installer-cli/config/trusted-keys.json')) {
        throw new RuntimeException('PHAR build modified the source trust maps.');
    }

    $tamperedDirectory = $temporary.'/tampered';
    mkdir($tamperedDirectory, 0700);
    file_put_contents($bootstrapDirectory.'/bootstrap.zip', 'tampered');
    [$code, $stdout, $stderr] = $run([PHP_BINARY, '-d', 'phar.readonly=0', $root.'/tools/build-phars.php', $version, $tamperedDirectory], $environment);
    if ($code === 0 || !str_contains($stdout.$stderr, 'size or SHA-256')) throw new RuntimeException('PHAR builder accepted tampered signed bootstrap bytes.');

    fwrite(STDOUT, "Signed bootstrap assets are verified, embedded, staged from PHAR storage, and installed through the package manager.\n");
} finally {
    $remove($temporary);
}
