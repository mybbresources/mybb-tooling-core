<?php
declare(strict_types=1);

namespace MyBB\Tooling;

final class CatalogPagination
{
    public static function apiListUrl(string $baseUrl, string $search = '', string $cursor = '', int $limit = 50): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || parse_url($baseUrl, PHP_URL_SCHEME) !== 'https') {
            throw new \InvalidArgumentException('Catalog API base URL must use HTTPS.');
        }
        if ($limit < 1 || $limit > 50) {
            throw new \InvalidArgumentException('Catalog page size must be between 1 and 50.');
        }

        $query = ['limit' => $limit];
        if ($search !== '') $query['q'] = $search;
        if ($cursor !== '') $query['cursor'] = $cursor;
        return $baseUrl . '/plugins?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public static function adminNextPageUrl(string $search, string $cursor): string
    {
        if ($cursor === '' || strlen($cursor) > 512) {
            throw new \InvalidArgumentException('Catalog pagination cursor is invalid.');
        }
        $query = ['module' => 'config-mybbresources_repository', 'cursor' => $cursor];
        if ($search !== '') $query['q'] = $search;
        return 'index.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
