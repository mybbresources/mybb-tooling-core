<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/autoload.php';
require_once dirname(__DIR__).'/mybb-installer-cli/src/InstallTrust.php';

$root = dirname(__DIR__);
$version = $argv[1] ?? '0.1.0';
$providedOutput = $argv[2] ?? null;
if (!preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/', $version)) {
    throw new InvalidArgumentException('Usage: php tests/phar-smoke.php [VERSION [OUTPUT_DIR]]');
}
$ownsTemporary = $providedOutput === null;
$temporary = sys_get_temp_dir().'/mybb-phar-smoke-'.bin2hex(random_bytes(6));
$output = $providedOutput === null ? $temporary : $providedOutput;
if ($ownsTemporary && !mkdir($temporary, 0700)) throw new RuntimeException('Could not create PHAR smoke-test directory.');
$run = static function (array $command): array {
    $pipes = [];
    $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start PHAR command.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    @rmdir($path);
};
try {
    if ($ownsTemporary) {
        [$code, $stdout, $stderr] = $run([PHP_BINARY, '-d', 'phar.readonly=0', $root.'/tools/build-phars.php', $version, $output]);
        if ($code !== 0 || $stderr !== '') throw new RuntimeException('PHAR build failed: '.$stdout.$stderr);
    }
    foreach (['mybb-cli.phar'=>'Usage: mybb COMMAND', 'mybb-install.phar'=>'Usage: mybb-install check'] as $filename=>$expectedHelp) {
        $path = rtrim($output, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;
        if (!is_file($path) || !is_executable($path)) throw new RuntimeException('PHAR is missing or not executable: '.$filename);
        $checksum = hash_file('sha256', $path);
        $checksumFile = trim((string)file_get_contents($path.'.sha256'));
        if (!is_string($checksum) || !str_starts_with($checksumFile, $checksum.'  '.$filename)) throw new RuntimeException('PHAR checksum mismatch: '.$filename);
        if ($filename === 'mybb-install.phar') {
            $phar = new Phar($path);
            if (!$phar->offsetExists('mybb-installer-cli/src/InstallPlanOutput.php') || !$phar->offsetExists('mybb-installer-cli/src/InstallRecovery.php') || !$phar->offsetExists('mybb-installer-cli/src/InstallTrust.php')) throw new RuntimeException('Installer PHAR omits required plan, recovery, or trust validation code.');
            $bootstrapNames = ['bootstrap.json','bootstrap.zip','repository.json','repository.zip','trusted-keys.json'];
            $embeddedCount = 0;
            foreach ($bootstrapNames as $asset) if ($phar->offsetExists('mybb-installer-cli/dist/'.$asset)) $embeddedCount++;
            if ($embeddedCount !== 0) {
                if ($embeddedCount !== count($bootstrapNames)) throw new RuntimeException('Installer PHAR contains an incomplete companion bootstrap bundle.');
                $trustPath = 'mybb-installer-cli/config/trusted-keys.json';
                $keys = json_decode($phar[$trustPath]->getContent(), true, 512, JSON_THROW_ON_ERROR);
                $bundleKeys = json_decode($phar['mybb-installer-cli/dist/trusted-keys.json']->getContent(), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($keys) || !$keys || $keys !== $bundleKeys) throw new RuntimeException('Installer PHAR bootstrap keys do not match a nonempty pinned trust map.');
                foreach ([['bootstrap','mybb-toolchain'],['repository','mybbresources-repository']] as [$prefix,$slug]) {
                    $manifestPath = 'mybb-installer-cli/dist/'.$prefix.'.json';
                    $archivePath = 'mybb-installer-cli/dist/'.$prefix.'.zip';
                    $manifest = MyBB\Tooling\Manifest::decode($phar[$manifestPath]->getContent());
                    MyBB\Tooling\Manifest::verifySignature($manifest, $keys);
                    $bytes = $phar[$archivePath]->getContent();
                    if ($manifest['slug'] !== $slug || $manifest['version'] !== $version || strlen($bytes) !== $manifest['package']['size'] || !hash_equals($manifest['package']['sha256'], hash('sha256', $bytes))) {
                        throw new RuntimeException('Installer PHAR embedded a bootstrap package that failed identity, version, size, or digest verification.');
                    }
                }
                $cli = new Phar(dirname($path).DIRECTORY_SEPARATOR.'mybb-cli.phar');
                if (!$cli->offsetExists('mybb-cli/config/trusted-keys.json') || json_decode($cli['mybb-cli/config/trusted-keys.json']->getContent(), true, 512, JSON_THROW_ON_ERROR) !== $keys) {
                    throw new RuntimeException('CLI and installer PHARs do not share the same pinned trust map.');
                }
                $archiveUri = 'phar://'.realpath($path).'/';
                MyBB\Installer\InstallTrust::assertSeparate($archiveUri.$trustPath, $archiveUri.'mybb-installer-cli/dist/trusted-keys.json');
            }
        }
        $metadata = (new Phar($path))->getMetadata();
        if (!is_array($metadata) || ($metadata['version'] ?? null) !== $version) throw new RuntimeException('PHAR version metadata mismatch: '.$filename);
        [$code, $help, $stderr] = $run([$path, '--help']);
        if ($code !== 0 || $stderr !== '' || !str_contains($help, $expectedHelp)) throw new RuntimeException('Self-contained PHAR smoke test failed for '.$filename.': '.$help.$stderr);
    }
    if ($ownsTemporary) {
        [$code, $stdout, $stderr] = $run([PHP_BINARY, '-d', 'phar.readonly=0', $root.'/tools/build-phars.php', $version, $output]);
        if ($code === 0 || !str_contains($stdout.$stderr, 'Refusing to overwrite')) throw new RuntimeException('PHAR builder must preserve existing artifacts unless --force is explicit.');
    }
    fwrite(STDOUT, "Both {$version} self-contained PHAR entrypoints built or verified, checksum-checked, and executed.\n");
} finally {
    if ($ownsTemporary) $remove($temporary);
}
