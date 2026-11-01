<?php
declare(strict_types=1);
namespace MyBB\Tooling;

use RuntimeException;

final class CatalogClient
{
    private const MAX_PACKAGE_BYTES = 67108864;
    private const MAX_JSON_BYTES = 2097152;

    private string $origin;

    public function __construct(string $origin = 'https://plugins.mybbresources.com')
    {
        $parts = parse_url($origin);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Catalog origin must be a plain HTTPS origin');
        }
        $this->origin = rtrim($origin, '/');
    }

    public function list(string $query = '', ?string $cursor = null): array
    {
        $url = $this->origin . '/api/v1/plugins?limit=50';
        if ($query !== '') $url .= '&q=' . rawurlencode($query);
        if ($cursor !== null) $url .= '&cursor=' . rawurlencode($cursor);
        return $this->json($url);
    }

    public function plugin(string $slug): array
    {
        return $this->json($this->origin . '/api/v1/plugins/' . rawurlencode($slug));
    }

    public function release(string $slug, string $version): array
    {
        return $this->json($this->origin . '/api/v1/plugins/' . rawurlencode($slug) . '/releases/' . rawurlencode($version));
    }

    public function download(array $manifest): string
    {
        $url = $manifest['package']['url'] ?? null;
        $expectedSize = $manifest['package']['size'] ?? null;
        if (!is_string($url) || !str_starts_with($url, $this->origin . '/api/v1/')) {
            throw new CatalogException('invalid_package_url', 'Catalog returned a package URL outside the configured origin.', false);
        }
        if (!is_int($expectedSize) || $expectedSize < 1 || $expectedSize > self::MAX_PACKAGE_BYTES) {
            throw new CatalogException('package_integrity_failure', 'Catalog returned an invalid or oversized package declaration.', false);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'mybb-catalog-');
        if ($tmp === false) throw new CatalogException('local_io_error', 'Could not create a private package download file.', false);
        $fp = @fopen($tmp, 'wb');
        if ($fp === false) {
            @unlink($tmp);
            throw new CatalogException('local_io_error', 'Could not open a private package download file.', false);
        }
        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fp);
            @unlink($tmp);
            throw new CatalogException('catalog_transport_error', 'Could not initialize the HTTPS package request.', false);
        }

        $downloaded = 0;
        $limitExceeded = false;
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'mybb-cli/0.1',
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static function ($resource, $downloadTotal, $current) use (&$downloaded, &$limitExceeded, $expectedSize): int {
                $downloaded = (int)$current;
                if ($downloaded > $expectedSize || $downloaded > self::MAX_PACKAGE_BYTES) {
                    $limitExceeded = true;
                    return 1;
                }
                return 0;
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);
        fclose($fp);

        if ($ok === false) {
            @unlink($tmp);
            throw self::classifyTransportFailure($curlErrno, $curlError, $limitExceeded);
        }
        if ($status < 200 || $status >= 300) {
            @unlink($tmp);
            throw self::classifyHttpFailure($status, [], 'Package download was rejected by the catalog.');
        }
        $actualSize = filesize($tmp);
        if (!is_int($actualSize) || $actualSize !== $expectedSize) {
            @unlink($tmp);
            throw new CatalogException('package_integrity_failure', 'Downloaded package size does not match its signed manifest.', false, $status);
        }
        return $tmp;
    }

    private function json(string $url): array
    {
        $body = '';
        $tooLarge = false;
        $headers = [];
        $ch = curl_init($url);
        if ($ch === false) throw new CatalogException('catalog_transport_error', 'Could not initialize the HTTPS catalog request.', false);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => static function ($resource, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_JSON_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($resource, string $line) use (&$headers): int {
                $length = strlen($line);
                $separator = strpos($line, ':');
                if ($separator !== false) $headers[strtolower(trim(substr($line, 0, $separator)))] = trim(substr($line, $separator + 1));
                return $length;
            },
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'mybb-cli/0.1',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($ok === false) throw self::classifyTransportFailure($curlErrno, $curlError, $tooLarge);
        $decoded = json_decode($body, true);
        if ($status < 200 || $status >= 300) {
            $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
            throw self::classifyHttpFailure($status, $error, 'Catalog request failed.', $headers['x-request-id'] ?? null);
        }
        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            throw new CatalogException('invalid_api_response', 'Catalog returned an invalid API v1 response.', false, $status, $headers['x-request-id'] ?? null);
        }
        return $decoded['data'];
    }

    /** Build stable errors from the shared JSON error envelope and HTTP status. */
    public static function classifyHttpFailure(int $status, array $error = [], string $fallbackMessage = 'Catalog request failed.', ?string $headerRequestId = null): CatalogException
    {
        $defaults = match ($status) {
            401, 403 => ['authorization_failed', false],
            404 => ['not_found', false],
            409 => ['conflict', false],
            410 => ['release_revoked', false],
            422 => ['validation_error', false],
            429 => ['rate_limited', true],
            default => $status >= 500 ? ['catalog_server_error', true] : ['catalog_http_error', false],
        };
        $code = $error['code'] ?? $defaults[0];
        if (!is_string($code) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code)) $code = $defaults[0];
        $message = $error['message'] ?? $fallbackMessage;
        if (!is_string($message) || trim($message) === '') $message = $fallbackMessage;
        $retryable = is_bool($error['retryable'] ?? null) ? $error['retryable'] : $defaults[1];
        $requestId = $error['request_id'] ?? $headerRequestId;
        if (!is_string($requestId) || !preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $requestId)) $requestId = null;
        return new CatalogException($code, self::safeMessage($message), $retryable, $status, $requestId);
    }

    /** Classify transport failures without exposing raw libcurl text to normal CLI output. */
    public static function classifyTransportFailure(int $curlErrno, string $curlMessage, bool $sizeLimitExceeded = false): CatalogException
    {
        if ($sizeLimitExceeded) return new CatalogException('response_too_large', 'Catalog response exceeds the allowed byte limit.', false);
        $transient = [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEDOUT, CURLE_RECV_ERROR, CURLE_SEND_ERROR, CURLE_GOT_NOTHING, CURLE_PARTIAL_FILE];
        if (in_array($curlErrno, $transient, true)) return new CatalogException('catalog_unavailable', 'Catalog could not be reached; retry when the network or service is available.', true);
        // The peer-verification errno was renamed between libcurl versions; include its legacy
        // numeric value and the portable PHP constants available on supported curl builds.
        $tlsErrors = [CURLE_SSL_CONNECT_ERROR, CURLE_SSL_CERTPROBLEM, CURLE_SSL_CACERT, CURLE_SSL_CACERT_BADFILE, CURLE_SSL_PINNEDPUBKEYNOTMATCH, 51];
        if (in_array($curlErrno, $tlsErrors, true) || str_contains(strtolower($curlMessage), 'certificate') || str_contains(strtolower($curlMessage), 'ssl')) return new CatalogException('catalog_tls_error', 'Catalog TLS verification failed; the response was not trusted.', false);
        return new CatalogException('catalog_transport_error', 'The HTTPS catalog request failed before a valid response was received.', false);
    }

    private static function safeMessage(string $message): string
    {
        $message = preg_replace('/[\x00-\x1f\x7f-\x9f]/u', ' ', trim($message)) ?? 'Catalog request failed.';
        return substr($message, 0, 500);
    }
}
