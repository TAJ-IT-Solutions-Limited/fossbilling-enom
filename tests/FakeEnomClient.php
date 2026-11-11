<?php

declare(strict_types=1);

use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class FakeEnomResponse implements ResponseInterface
{
    public function __construct(private string $body, private int $status = 200)
    {
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getHeaders(bool $throw = true): array
    {
        return ['content-type' => ['text/plain']];
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

final class FakeEnomChunk implements ChunkInterface
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

final class FakeEnomStream implements ResponseStreamInterface
{
    public function key(): ResponseInterface
    {
        return new FakeEnomResponse('');
    }

    public function current(): ChunkInterface
    {
        return new FakeEnomChunk();
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

final class FakeEnomClient implements HttpClientInterface
{
    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    public array $calls = [];

    public int $status = 200;

    /** @param list<string> $bodies */
    public function __construct(private array $bodies)
    {
    }

    public function withOptions(array $options): static
    {
        return $this;
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->calls[] = [$method, $url, $options];
        $body = array_shift($this->bodies);
        if (!is_string($body)) {
            throw new RuntimeException('no fake Enom body left');
        }
        if (str_starts_with($body, 'THROW ')) {
            throw new RuntimeException(substr($body, 6));
        }

        return new FakeEnomResponse($body, $this->status);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return new FakeEnomStream();
    }
}
