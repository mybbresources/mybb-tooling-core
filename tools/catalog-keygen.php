#!/usr/bin/env php
<?php
declare(strict_types=1);

if ($argc !== 3 || !preg_match('/^[a-z0-9][a-z0-9-]{2,63}$/', $argv[1])) {
    fwrite(STDERR, "Usage: php catalog-keygen.php KEY_ID EMPTY_OUTPUT_DIRECTORY\nKey ID must be 3-64 lowercase letters, digits, or hyphens.\n");
    exit(2);
}

[$script, $id, $directory] = $argv;
$directory = rtrim($directory, DIRECTORY_SEPARATOR);
umask(0077);

try {
    if ($directory === '' || is_link($directory) || (file_exists($directory) && !is_dir($directory))) {
        throw new RuntimeException('Output must be a real directory, not a symlink or file.');
    }
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
        throw new RuntimeException('Cannot create private output directory.');
    }
    $stat = lstat($directory);
    if ($stat === false || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) {
        throw new RuntimeException('Output directory must be owned by the current user.');
    }
    $entries = scandir($directory);
    if ($entries === false || count($entries) !== 2) {
        throw new RuntimeException('Output directory must be empty.');
    }
    if (!chmod($directory, 0700)) {
        throw new RuntimeException('Cannot restrict output directory permissions.');
    }

    $pair = sodium_crypto_sign_keypair();
    $secret = base64_encode(sodium_crypto_sign_secretkey($pair));
    $public = json_encode([$id => [
        'algorithm' => 'Ed25519',
        'public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
        'not_before' => time(),
    ]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    // Exclusive creation under umask 0077 protects the key from its first byte,
    // including when the caller supplied an existing permissive directory.
    foreach (['ed25519-private.key' => $secret, 'trusted-keys.json' => $public] as $name => $bytes) {
        $handle = @fopen($directory . '/' . $name, 'x');
        if ($handle === false) throw new RuntimeException('Cannot exclusively create key output; existing files were not overwritten.');
        try {
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($handle, substr($bytes, $offset));
                if ($written === false || $written === 0) throw new RuntimeException('Could not finish writing key output. Preserve the private output directory for recovery.');
                $offset += $written;
            }
            if (!fflush($handle)) throw new RuntimeException('Could not flush key output.');
        } finally {
            fclose($handle);
        }
    }
    sodium_memzero($secret);
    sodium_memzero($pair);
    if (!chmod($directory . '/trusted-keys.json', 0644)) throw new RuntimeException('Could not set public-key map permissions.');
    fwrite(STDOUT, "Created signing key pair in the private output directory. Keep ed25519-private.key secret and back it up securely.\nDistribute only trusted-keys.json through a verified release channel.\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
