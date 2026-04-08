<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Object storage abstraction for attachments.
 *
 * Supports:
 *   - local:  Local filesystem (XAMPP dev)
 *   - s3:     S3-compatible (AWS, MinIO, DigitalOcean Spaces, etc.)
 *
 * Configuration via env:
 *   STORAGE_DRIVER=local|s3
 *   STORAGE_LOCAL_PATH=storage/uploads
 *   STORAGE_S3_BUCKET=cro-uploads
 *   STORAGE_S3_REGION=eu-central-1
 *   STORAGE_S3_ENDPOINT=https://s3.eu-central-1.amazonaws.com
 *   STORAGE_S3_KEY=AKIAIOSFODNN7EXAMPLE
 *   STORAGE_S3_SECRET=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY
 *   STORAGE_S3_CDN_URL=https://cdn.example.com
 */
final class ObjectStorage
{
    private static string $driver = 'local';
    private static array $config = [];

    public static function init(?array $config = null): void
    {
        $config = $config ?? [
            'driver' => Env::get('STORAGE_DRIVER', 'local'),
            'local' => [
                'path' => Env::get('STORAGE_LOCAL_PATH', realpath(__DIR__ . '/../../storage/uploads') ?: __DIR__ . '/../../storage/uploads'),
            ],
            's3' => [
                'bucket' => Env::get('STORAGE_S3_BUCKET', 'cro-uploads'),
                'region' => Env::get('STORAGE_S3_REGION', 'eu-central-1'),
                'endpoint' => Env::get('STORAGE_S3_ENDPOINT', ''),
                'key' => Env::get('STORAGE_S3_KEY', ''),
                'secret' => Env::get('STORAGE_S3_SECRET', ''),
                'cdn_url' => Env::get('STORAGE_S3_CDN_URL', ''),
                'path_style' => Env::bool('STORAGE_S3_PATH_STYLE', false),
            ],
        ];

        self::$driver = $config['driver'] ?? 'local';
        self::$config = $config;
    }

    public static function driver(): string
    {
        return self::$driver;
    }

    // ── Store ────────────────────────────────────

    /**
     * Store a file from a local path.
     *
     * @param string $localPath  Path to the temporary file
     * @param string $storageName  Target storage name (e.g. "a1b2c3.jpg")
     * @param string $mimeType
     * @return string  The storage key/path
     */
    public static function put(string $localPath, string $storageName, string $mimeType = ''): string
    {
        return match (self::$driver) {
            's3' => self::putS3($localPath, $storageName, $mimeType),
            default => self::putLocal($localPath, $storageName),
        };
    }

    /**
     * Store from raw content string.
     */
    public static function putContent(string $content, string $storageName, string $mimeType = ''): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'cro_');
        file_put_contents($tmpFile, $content);
        try {
            return self::put($tmpFile, $storageName, $mimeType);
        } finally {
            @unlink($tmpFile);
        }
    }

    // ── Retrieve ─────────────────────────────────

    /**
     * Get file contents.
     */
    public static function get(string $storageName): ?string
    {
        return match (self::$driver) {
            's3' => self::getS3($storageName),
            default => self::getLocal($storageName),
        };
    }

    /**
     * Get a URL for the stored file (for CDN delivery).
     */
    public static function url(string $storageName): string
    {
        return match (self::$driver) {
            's3' => self::urlS3($storageName),
            default => self::urlLocal($storageName),
        };
    }

    /**
     * Generate a pre-signed URL (S3 only, time-limited access).
     * Falls back to regular URL on local driver.
     */
    public static function presignedUrl(string $storageName, int $expiresInSeconds = 3600): string
    {
        if (self::$driver !== 's3') {
            return self::urlLocal($storageName);
        }

        return self::generatePresignedUrl($storageName, $expiresInSeconds);
    }

    /**
     * Stream file to output (for download endpoint).
     */
    public static function stream(string $storageName, string $mimeType, int $fileSize, string $originalName): never
    {
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . $fileSize);
        header('Content-Disposition: inline; filename="' . addcslashes($originalName, '"\\') . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');

        if (self::$driver === 's3') {
            // Redirect to pre-signed URL for S3
            $url = self::presignedUrl($storageName, 3600);
            header('Location: ' . $url, true, 302);
            exit;
        }

        // Local: stream directly
        $path = self::localPath($storageName);
        if (!file_exists($path)) {
            http_response_code(404);
            exit;
        }
        readfile($path);
        exit;
    }

    // ── Delete ───────────────────────────────────

    /**
     * Delete a stored file.
     */
    public static function delete(string $storageName): bool
    {
        return match (self::$driver) {
            's3' => self::deleteS3($storageName),
            default => self::deleteLocal($storageName),
        };
    }

    /**
     * Check if a file exists.
     */
    public static function exists(string $storageName): bool
    {
        return match (self::$driver) {
            's3' => self::existsS3($storageName),
            default => self::existsLocal($storageName),
        };
    }

    // ── Local Driver ─────────────────────────────

    private static function localPath(string $name): string
    {
        $base = self::$config['local']['path'] ?? __DIR__ . '/../../storage/uploads';
        return $base . DIRECTORY_SEPARATOR . basename($name);
    }

    private static function putLocal(string $localPath, string $storageName): string
    {
        $dest = self::localPath($storageName);
        $dir = dirname($dest);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (is_uploaded_file($localPath)) {
            move_uploaded_file($localPath, $dest);
        } else {
            copy($localPath, $dest);
        }

        return $storageName;
    }

    private static function getLocal(string $storageName): ?string
    {
        $path = self::localPath($storageName);
        $realPath = realpath($path);
        $realBase = realpath(self::$config['local']['path'] ?? '');
        if ($realPath === false || ($realBase && !str_starts_with($realPath, $realBase))) {
            return null;
        }
        return file_exists($path) ? file_get_contents($path) : null;
    }

    private static function urlLocal(string $storageName): string
    {
        $appUrl = Env::get('APP_URL', 'http://localhost/chat-api/public');
        return rtrim($appUrl, '/') . '/attachments/' . urlencode($storageName);
    }

    private static function deleteLocal(string $storageName): bool
    {
        $path = self::localPath($storageName);
        $realPath = realpath($path);
        $realBase = realpath(self::$config['local']['path'] ?? '');
        if ($realPath === false || ($realBase && !str_starts_with($realPath, $realBase))) {
            return false;
        }
        return file_exists($path) && unlink($path);
    }

    private static function existsLocal(string $storageName): bool
    {
        return file_exists(self::localPath($storageName));
    }

    // ── S3 Driver (cURL-based, no SDK required) ──

    private static function putS3(string $localPath, string $storageName, string $mimeType): string
    {
        $content = file_get_contents($localPath);
        $bucket = self::$config['s3']['bucket'];
        $region = self::$config['s3']['region'];
        $endpoint = self::s3Endpoint();

        $url = $endpoint . '/' . urlencode($storageName);
        $headers = self::s3Sign('PUT', '/' . $bucket . '/' . $storageName, $content, [
            'Content-Type' => $mimeType ?: 'application/octet-stream',
            'x-amz-acl' => 'private',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $content,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            Logger::error('storage.s3_put_failed', [
                'file' => $storageName,
                'http_code' => $httpCode,
                'response' => substr((string) $response, 0, 500),
            ]);
            throw new \RuntimeException("S3 upload failed (HTTP {$httpCode})");
        }

        return $storageName;
    }

    private static function getS3(string $storageName): ?string
    {
        $endpoint = self::s3Endpoint();
        $bucket = self::$config['s3']['bucket'];
        $url = $endpoint . '/' . urlencode($storageName);

        $headers = self::s3Sign('GET', '/' . $bucket . '/' . $storageName);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode === 200) ? $response : null;
    }

    private static function deleteS3(string $storageName): bool
    {
        $endpoint = self::s3Endpoint();
        $bucket = self::$config['s3']['bucket'];
        $url = $endpoint . '/' . urlencode($storageName);

        $headers = self::s3Sign('DELETE', '/' . $bucket . '/' . $storageName);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 300;
    }

    private static function existsS3(string $storageName): bool
    {
        $endpoint = self::s3Endpoint();
        $bucket = self::$config['s3']['bucket'];
        $url = $endpoint . '/' . urlencode($storageName);

        $headers = self::s3Sign('HEAD', '/' . $bucket . '/' . $storageName);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'HEAD',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    private static function urlS3(string $storageName): string
    {
        $cdn = self::$config['s3']['cdn_url'] ?? '';
        if ($cdn !== '') {
            return rtrim($cdn, '/') . '/' . urlencode($storageName);
        }
        return self::s3Endpoint() . '/' . urlencode($storageName);
    }

    /**
     * Generate pre-signed URL (AWS Signature V4).
     */
    private static function generatePresignedUrl(string $storageName, int $expiresIn): string
    {
        $bucket = self::$config['s3']['bucket'];
        $region = self::$config['s3']['region'];
        $key = self::$config['s3']['key'];
        $secret = self::$config['s3']['secret'];
        $endpoint = self::s3Endpoint();

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $date = $now->format('Ymd');
        $timestamp = $now->format('Ymd\THis\Z');
        $scope = "{$date}/{$region}/s3/aws4_request";

        $canonicalUri = '/' . rawurlencode($storageName);
        $query = http_build_query([
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => "{$key}/{$scope}",
            'X-Amz-Date' => $timestamp,
            'X-Amz-Expires' => $expiresIn,
            'X-Amz-SignedHeaders' => 'host',
        ]);

        // Canonical request
        $host = parse_url($endpoint, PHP_URL_HOST);
        $canonical = "GET\n{$canonicalUri}\n{$query}\nhost:{$host}\n\nhost\nUNSIGNED-PAYLOAD";
        $canonicalHash = hash('sha256', $canonical);

        // String to sign
        $stringToSign = "AWS4-HMAC-SHA256\n{$timestamp}\n{$scope}\n{$canonicalHash}";

        // Signing key
        $dateKey = hash_hmac('sha256', $date, "AWS4{$secret}", true);
        $regionKey = hash_hmac('sha256', $region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', 's3', $regionKey, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);

        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return "{$endpoint}{$canonicalUri}?{$query}&X-Amz-Signature={$signature}";
    }

    // ── S3 Signing (AWS Signature V4) ────────────

    private static function s3Endpoint(): string
    {
        $endpoint = self::$config['s3']['endpoint'] ?? '';
        if ($endpoint !== '') {
            return rtrim($endpoint, '/');
        }

        $bucket = self::$config['s3']['bucket'];
        $region = self::$config['s3']['region'];

        if (self::$config['s3']['path_style'] ?? false) {
            return "https://s3.{$region}.amazonaws.com/{$bucket}";
        }
        return "https://{$bucket}.s3.{$region}.amazonaws.com";
    }

    /**
     * Generate AWS Signature V4 headers for S3 requests.
     */
    private static function s3Sign(string $method, string $path, string $body = '', array $extraHeaders = []): array
    {
        $key = self::$config['s3']['key'];
        $secret = self::$config['s3']['secret'];
        $region = self::$config['s3']['region'];

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $date = $now->format('Ymd');
        $timestamp = $now->format('Ymd\THis\Z');

        $endpoint = self::s3Endpoint();
        $host = parse_url($endpoint, PHP_URL_HOST);

        $payloadHash = hash('sha256', $body);

        // Build headers
        $headers = array_merge($extraHeaders, [
            'Host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $timestamp,
        ]);

        // Canonical headers (sorted lowercase)
        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaders = [];
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= strtolower($k) . ':' . trim($v) . "\n";
            $signedHeaders[] = strtolower($k);
        }
        $signedHeadersStr = implode(';', $signedHeaders);

        // Canonical request
        $canonical = "{$method}\n{$path}\n\n{$canonicalHeaders}\n{$signedHeadersStr}\n{$payloadHash}";
        $canonicalHash = hash('sha256', $canonical);

        // String to sign
        $scope = "{$date}/{$region}/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$timestamp}\n{$scope}\n{$canonicalHash}";

        // Signing key
        $dateKey = hash_hmac('sha256', $date, "AWS4{$secret}", true);
        $regionKey = hash_hmac('sha256', $region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', 's3', $regionKey, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);

        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $authHeader = "AWS4-HMAC-SHA256 Credential={$key}/{$scope}, SignedHeaders={$signedHeadersStr}, Signature={$signature}";

        // Convert to cURL header array
        $curlHeaders = ["Authorization: {$authHeader}"];
        foreach ($headers as $k => $v) {
            $curlHeaders[] = "{$k}: {$v}";
        }
        return $curlHeaders;
    }

    // ── Config Info ──────────────────────────────

    public static function info(): array
    {
        return [
            'driver' => self::$driver,
            'config' => match (self::$driver) {
                's3' => [
                    'bucket' => self::$config['s3']['bucket'] ?? '',
                    'region' => self::$config['s3']['region'] ?? '',
                    'endpoint' => self::$config['s3']['endpoint'] ?? '',
                    'cdn_url' => self::$config['s3']['cdn_url'] ?? '',
                ],
                default => [
                    'path' => self::$config['local']['path'] ?? '',
                ],
            },
        ];
    }

    /** @internal For testing */
    public static function reset(): void
    {
        self::$driver = 'local';
        self::$config = [];
    }
}
