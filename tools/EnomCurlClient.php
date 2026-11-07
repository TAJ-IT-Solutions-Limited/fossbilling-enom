<?php

declare(strict_types=1);

use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Used only when the billing server has no Symfony HTTP client.
 * Require this file after FOSSBilling's autoload so the contracts exist.
 */

final class EnomCurlResponse implements ResponseInterface
{
    public function __construct(private string $body, private int $status, private array $headers)
    {
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getHeaders(bool $throw = true): array
    {
        return $this->headers;
    }

    public function getContent(bool $throw = true): string
    {
        return $this->body;
    }

    public function toArray(bool $throw = true): array
    {
        return [];
    }

    public function cancel(): void
    {
    }

    public function getInfo(?string $type = null): mixed
    {
        return $type === null ? ['http_code' => $this->status] : null;
    }
}

final class EnomCurlChunk implements ChunkInterface
{
    public function isTimeout(): bool
    {
        return false;
    }

    public function isFirst(): bool
    {
        return false;
    }

    public function isLast(): bool
    {
        return true;
    }

    public function getInformationalStatus(): ?array
    {
        return null;
    }

    public function getContent(): string
    {
        return '';
    }

    public function getOffset(): int
    {
        return 0;
    }

    public function getError(): ?string
    {
        return null;
    }
}

final class EnomCurlStream implements ResponseStreamInterface
{
    public function key(): ResponseInterface
    {
        return new EnomCurlResponse('', 0, []);
    }

    public function current(): ChunkInterface
    {
        return new EnomCurlChunk();
    }

    public function next(): void
    {
    }

    public function valid(): bool
    {
        return false;
    }

    public function rewind(): void
    {
    }
}

final class EnomCurlClient implements HttpClientInterface
{
    public function __construct(private int $timeout = 90)
    {
    }

    public function withOptions(array $options): static
    {
        $copy = clone $this;
        if (isset($options['timeout'])) {
            $copy->timeout = (int) $options['timeout'];
        }

        return $copy;
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if ($method !== 'POST') {
            throw new RuntimeException('Enom live check sends POST only');
        }
        $fields = $options['body'] ?? [];
        if (!is_array($fields)) {
            throw new RuntimeException('Enom live check posts form fields');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('curl is not available');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);
        if (!is_string($raw)) {
            throw new RuntimeException('curl exec failed');
        }
        $headerBlob = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $headers = ['content-type' => ['text/plain']];
        if (preg_match('/^content-type:\s*(.+)$/im', $headerBlob, $match) === 1) {
            $headers['content-type'] = [trim($match[1])];
        }

        return new EnomCurlResponse($body, $status, $headers);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return new EnomCurlStream();
    }
}
