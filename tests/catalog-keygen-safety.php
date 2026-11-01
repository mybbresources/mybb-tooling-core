<?php
declare(strict_types=1);

$root = sys_get_temp_dir() . '/mybb-keygen-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$tool = dirname(__DIR__) . '/tools/catalog-keygen.php';
$run = static function (string $directory) use ($tool): array {
    $process = proc_open([PHP_BINARY, $tool, 'fixture-key', $directory], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start key generator.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $output];
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

try {
    // Cover both newly created output and an operator-created permissive directory.
    foreach (['new', 'existing'] as $name) {
        $directory = $root . '/' . $name;
        if ($name === 'existing') { mkdir($directory, 0755); chmod($directory, 0755); }
        [$status, $output] = $run($directory);
        $assert($status === 0, 'Key generation should succeed.');
        clearstatcache();
        $assert((fileperms($directory) & 0777) === 0700, 'Key directory must be private.');
        $assert((fileperms($directory . '/ed25519-private.key') & 0777) === 0600, 'Private key must be owner-only.');
        $secretEncoded = file_get_contents($directory . '/ed25519-private.key');
        $secret = base64_decode($secretEncoded, true);
        $map = json_decode(file_get_contents($directory . '/trusted-keys.json'), true, 512, JSON_THROW_ON_ERROR);
        $assert($secret !== false && strlen($secret) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 'Private key must be Ed25519.');
        $assert(hash_equals(base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret)), $map['fixture-key']['public_key']), 'Public and private keys must match.');
        $assert(!str_contains($output, $secretEncoded), 'Output must not disclose private key bytes.');
        [$retryStatus] = $run($directory);
        $assert($retryStatus !== 0 && file_get_contents($directory . '/ed25519-private.key') === $secretEncoded, 'Retry must preserve existing keys.');
    }
    mkdir($root . '/target', 0700);
    symlink($root . '/target', $root . '/link');
    [$status] = $run($root . '/link/');
    $assert($status !== 0 && count(scandir($root . '/target')) === 2, 'Symlink output must be rejected without writes.');
    echo "PASS private key creation, matching public map, secret-free output, retry preservation, and symlink refusal\n";
} finally {
    foreach (['new', 'existing'] as $name) {
        foreach (['ed25519-private.key', 'trusted-keys.json'] as $file) if (is_file($root . '/' . $name . '/' . $file)) unlink($root . '/' . $name . '/' . $file);
        if (is_dir($root . '/' . $name)) rmdir($root . '/' . $name);
    }
    if (is_link($root . '/link')) unlink($root . '/link');
    if (is_dir($root . '/target')) rmdir($root . '/target');
    rmdir($root);
}
