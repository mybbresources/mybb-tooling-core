#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';

use MyBB\Tooling\Manifest;

if ($argc < 5) {
    fwrite(STDERR, "Usage: php build-synthetic-samples.php KEY_ID PRIVATE_KEY_FILE TRUSTED_KEYS_JSON OUTPUT_DIRECTORY\n");
    exit(2);
}
[, $keyId, $privateFile, $trustedFile, $output] = $argv;
if (!preg_match('/^[a-z0-9][a-z0-9-]{2,63}$/', $keyId)) {
    fwrite(STDERR, "Invalid key ID.\n");
    exit(2);
}
$secret = base64_decode(trim((string) @file_get_contents($privateFile)), true);
$keys = json_decode((string) @file_get_contents($trustedFile), true, 512, JSON_THROW_ON_ERROR);
if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES || !is_array($keys) || !isset($keys[$keyId])) {
    throw new RuntimeException('A matching signing key and trusted public key are required.');
}
$public = base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
if (!hash_equals((string) ($keys[$keyId]['public_key'] ?? ''), $public)) {
    throw new RuntimeException('Private key does not match the selected trusted public key.');
}
if (!is_dir($output) && !mkdir($output, 0755, true)) {
    throw new RuntimeException('Cannot create output directory.');
}

$samples = dirname(__DIR__).'/examples/synthetic';
foreach (new DirectoryIterator($samples) as $sample) {
    if ($sample->isDot() || !$sample->isDir()) continue;
    $directory = $sample->getPathname();
    $manifest = json_decode((string) file_get_contents($directory.'/meta.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($manifest['source']['type'] ?? null) !== 'original') throw new RuntimeException('Synthetic samples must be authored originals.');
    $archivePath = rtrim($output, '/').'/'.$manifest['slug'].'-'.$manifest['version'].'.zip';
    $archive = new ZipArchive();
    if ($archive->open($archivePath, ZipArchive::CREATE|ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Cannot create sample archive: '.$archivePath);
    try {
        foreach ($manifest['files'] as $file) {
            if (!str_starts_with($file['source'], 'upload/')) throw new RuntimeException('Sample package source paths must start with upload/.');
            $sourcePath = $directory.'/files/'.substr($file['source'], strlen('upload/'));
            if (!is_file($sourcePath) || !$archive->addFile($sourcePath, $file['source'])) throw new RuntimeException('Missing sample source file: '.$sourcePath);
        }
    } finally {
        $archive->close();
    }
    $manifest['package']['sha256'] = hash_file('sha256', $archivePath);
    $manifest['package']['size'] = filesize($archivePath);
    $manifest['package']['key_id'] = $keyId;
    $manifest['package']['signature'] = '';
    $manifest['package']['url'] = 'https://plugins.mybbresources.com/api/v1/plugins/'.$manifest['slug'].'/releases/'.$manifest['version'].'/package';
    $manifest['package']['signature'] = base64_encode(sodium_crypto_sign_detached(Manifest::unsignedPayload($manifest), $secret));
    Manifest::validate($manifest);
    Manifest::verifySignature($manifest, $keys);
    $manifestPath = rtrim($output, '/').'/'.$manifest['slug'].'-'.$manifest['version'].'.json';
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    fwrite(STDOUT, $manifest['slug']."\n  {$archivePath}\n  {$manifestPath}\n");
}
