<?php

namespace App\Services;

use GuzzleHttp\ClientInterface;
use RuntimeException;

final class ImageProxyService
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    private const ALLOWED_MIME_TYPES = [
        'image/gif',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function __construct(private readonly ClientInterface $client) {}

    /** @return array{body: string, mime: string} */
    public function fetch(string $url): array
    {
        $target = $this->validatedTarget($url);

        $response = $this->client->request('GET', $url, [
            'allow_redirects' => false,
            'connect_timeout' => 5,
            'curl' => [CURLOPT_RESOLVE => [$target['resolve']]],
            'headers' => ['User-Agent' => 'News24h-ImageProxy/1.0'],
            'http_errors' => false,
            'stream' => true,
            'timeout' => 10,
            'verify' => true,
        ]);

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new RuntimeException('Upstream did not return a successful response.');
        }

        $declaredLength = $response->getHeaderLine('Content-Length');
        if ($declaredLength !== '' && (int) $declaredLength > self::MAX_BYTES) {
            throw new RuntimeException('Image exceeds the proxy size limit.');
        }

        $stream = $response->getBody();
        $body = '';
        while (! $stream->eof()) {
            $body .= $stream->read(8192);
            if (strlen($body) > self::MAX_BYTES) {
                throw new RuntimeException('Image exceeds the proxy size limit.');
            }
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);
        if (! is_string($mime) || ! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new RuntimeException('Upstream response is not a supported image.');
        }

        return ['body' => $body, 'mime' => $mime];
    }

    /** @return array{resolve: string} */
    private function validatedTarget(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new RuntimeException('Only public HTTP(S) image URLs are allowed.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (! in_array($port, [80, 443], true)) {
            throw new RuntimeException('Only standard HTTP(S) ports are allowed.');
        }

        $host = trim((string) $parts['host'], '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolveHost($host);
        if ($addresses === []) {
            throw new RuntimeException('Image host could not be resolved.');
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('Private and reserved image hosts are blocked.');
            }
        }

        $pinnedAddress = str_contains($addresses[0], ':') ? "[{$addresses[0]}]" : $addresses[0];

        return ['resolve' => "{$host}:{$port}:{$pinnedAddress}"];
    }

    /** @return list<string> */
    private function resolveHost(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records)) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
