<?php
declare(strict_types=1);
namespace MyBB\Tooling;

use RuntimeException;

final class Manifest
{
    public const ROLES = ['plugin', 'language', 'asset', 'admin', 'other'];

    public static function decode(string $json): array
    {
        $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::validate($manifest);
        return $manifest;
    }

    public static function validate(array $m): void
    {
        foreach (['schema_version','slug','name','version','description','authors','license','source','requirements','package','files','uninstall'] as $key) {
            if (!array_key_exists($key, $m)) throw new RuntimeException("Manifest missing required field: {$key}");
        }
        foreach (['source','requirements','package','files','uninstall'] as $key) if (!is_array($m[$key])) throw new RuntimeException("Manifest {$key} must be an object or array");
        if ($m['schema_version'] !== 1) throw new RuntimeException('Unsupported manifest schema version');
        foreach (['slug','name','version','description','license'] as $key) if (!is_string($m[$key]) || trim($m[$key])==='') throw new RuntimeException("Manifest {$key} must be a non-empty string");
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $m['slug'])) throw new RuntimeException('Invalid plugin slug');
        if (!preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/', $m['version'])) throw new RuntimeException('Invalid semantic version');
        if (!is_array($m['authors']) || !array_is_list($m['authors']) || !$m['authors'] || !array_is_list($m['files']) || !$m['files']) throw new RuntimeException('Manifest authors and files must be non-empty lists');
        foreach ($m['authors'] as $author) if (!is_string($author) || trim($author)==='') throw new RuntimeException('Manifest authors must be non-empty strings');
        if (!is_string($m['source']['type']??null)) throw new RuntimeException('Manifest source type must be a string');
        if (!in_array($m['source']['type'] ?? null, ['original','upstream'], true)) throw new RuntimeException('Invalid source type');
        if (($m['source']['type'] ?? '') === 'upstream' && (($m['source']['rights_status'] ?? '') !== 'verified')) throw new RuntimeException('Upstream redistribution rights are not verified');
        if (($m['source']['type'] ?? '') === 'upstream') {
            if (!is_string($m['source']['url']??null) || !filter_var($m['source']['url'],FILTER_VALIDATE_URL) || parse_url($m['source']['url'],PHP_URL_SCHEME)!=='https') throw new RuntimeException('Upstream source must use a canonical HTTPS URL');
            foreach (['upstream_id','upstream_version','attribution','rights_evidence_url'] as $field) if (!isset($m['source'][$field]) || (is_string($m['source'][$field]) && trim($m['source'][$field])==='') || !is_string($m['source'][$field])) throw new RuntimeException("Upstream source is missing {$field}");
            if (!filter_var($m['source']['rights_evidence_url'],FILTER_VALIDATE_URL) || parse_url($m['source']['rights_evidence_url'],PHP_URL_SCHEME)!=='https') throw new RuntimeException('Upstream rights evidence must use HTTPS');
        }
        foreach (['mybb','php'] as $requirement) { if (!isset($m['requirements'][$requirement]) || !is_string($m['requirements'][$requirement])) throw new RuntimeException("Missing {$requirement} compatibility range"); Compatibility::validateRange($m['requirements'][$requirement]); }
        foreach (['url','sha256','size','signature','key_id'] as $key) if (!array_key_exists($key, $m['package'])) throw new RuntimeException("Package missing {$key}");
        if (!is_string($m['package']['url']) || !is_string($m['package']['sha256']) || !is_string($m['package']['signature']) || !is_string($m['package']['key_id']) || trim($m['package']['key_id'])==='') throw new RuntimeException('Package URL, digest, signature, and key ID must be strings');
        if (!filter_var($m['package']['url'],FILTER_VALIDATE_URL)||parse_url($m['package']['url'],PHP_URL_SCHEME)!=='https') throw new RuntimeException('Package URL must use HTTPS');
        if (!preg_match('/^[a-f0-9]{64}$/', (string)$m['package']['sha256'])) throw new RuntimeException('Invalid package SHA-256');
        if (!is_int($m['package']['size']) || $m['package']['size'] < 1) throw new RuntimeException('Invalid package size');
        $sources = $destinations = [];
        foreach ($m['files'] as $file) {
            if (!is_array($file) || !isset($file['source'],$file['destination'],$file['role']) || !is_string($file['source']) || !is_string($file['destination']) || !is_string($file['role'])) throw new RuntimeException('Invalid file manifest entry');
            self::validateRelativePath($file['source']); self::validateRelativePath($file['destination']);
            if (!in_array($file['role'], self::ROLES, true)) throw new RuntimeException('Invalid file role');
            $sourceKey=strtolower($file['source']);$destinationKey=strtolower($file['destination']);
            if (isset($sources[$sourceKey]) || isset($destinations[$destinationKey])) throw new RuntimeException('Duplicate normalized source or destination path');
            $sources[$sourceKey] = $destinations[$destinationKey] = true;
        }
        if (($m['uninstall']['preserve_user_data'] ?? false) !== true) throw new RuntimeException('v1 requires preserve_user_data=true');
        if (($m['uninstall']['remove_owned_files'] ?? false) !== true) throw new RuntimeException('v1 requires remove_owned_files=true for ledger-owned paths');
    }

    public static function validateRelativePath(string $path): void
    {
        if ($path === '' || str_contains($path, "\\") || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) throw new RuntimeException("Unsafe relative path: {$path}");
        if(preg_match('/[^\x20-\x7e]/',$path))throw new RuntimeException('Non-ASCII package paths are not supported in v1');
        foreach (explode('/', $path) as $part) { $base=strtoupper(explode('.',$part,2)[0]);if ($part === '' || $part === '.' || $part === '..' || str_contains($part,':') || preg_match('/[\x00-\x1f\x7f]/', $part) || preg_match('/[. ]$/',$part) || in_array($base,['CON','PRN','AUX','NUL','COM1','COM2','COM3','COM4','COM5','COM6','COM7','COM8','COM9','LPT1','LPT2','LPT3','LPT4','LPT5','LPT6','LPT7','LPT8','LPT9'],true)) throw new RuntimeException("Unsafe relative path: {$path}"); }
        if (class_exists(\Normalizer::class) && \Normalizer::normalize($path, \Normalizer::FORM_C) !== $path) throw new RuntimeException("Non-canonical Unicode path: {$path}");
    }

    public static function validateArchiveEntryType(\ZipArchive $archive,int $index,bool $directory):void
    {
        $opsys=$attributes=0;if(!$archive->getExternalAttributesIndex($index,$opsys,$attributes)||$opsys!==\ZipArchive::OPSYS_UNIX)return;
        $type=($attributes>>16)&0170000;if($type===0)return;
        if($type===0120000)throw new RuntimeException('Archive symlinks are forbidden');
        if(($directory&&$type===0040000)||(!$directory&&$type===0100000))return;
        throw new RuntimeException('Archive contains an unsupported special file type');
    }

    public static function canonical(array $value): string
    {
        $sort = static function (&$v) use (&$sort): void { if (is_array($v)) { if (!array_is_list($v)) ksort($v, SORT_STRING); foreach ($v as &$child) $sort($child); } };
        $sort($value);
        return json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }

    public static function unsignedPayload(array $manifest): string
    {
        unset($manifest['package']['signature']);
        return self::canonical($manifest);
    }

    public static function verifySignature(array $manifest, array $trustedKeys, int $now = 0): void
    {
        $package = $manifest['package']; $key = $trustedKeys[$package['key_id']] ?? null;
        if (!$key) throw new RuntimeException('Signing key is not trusted');
        if (isset($key['expires_at']) && $key['expires_at'] < ($now ?: time())) throw new RuntimeException('Signing key has expired');
        if (($key['revoked'] ?? false) === true) throw new RuntimeException('Signing key has been revoked');
        if (isset($key['not_before']) && $key['not_before'] > ($now ?: time())) throw new RuntimeException('Signing key is not active yet');
        $public = base64_decode((string)$key['public_key'], true); $signature = base64_decode((string)$package['signature'], true);
        if ($public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) throw new RuntimeException('Malformed signing key or signature');
        if (!sodium_crypto_sign_verify_detached($signature, self::unsignedPayload($manifest), $public)) throw new RuntimeException('Manifest signature is invalid');
    }
}
