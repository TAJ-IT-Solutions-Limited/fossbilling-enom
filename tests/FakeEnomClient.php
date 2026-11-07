<?php

declare(strict_types=1);

final class FakeEnomResponse
{
    public function __construct(private string $body, private int $status = 200)
    {
    }

    public function getContent(bool $throw = true): string
    {
        return $this->body;
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getHeaders(bool $throw = true): array
    {
        return ['content-type' => ['text/plain']];
    }
}

final class FakeEnomClient
{
    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    public array $calls = [];

    /** @param list<string> $bodies */
    public function __construct(private array $bodies)
    {
    }

    public function withOptions(array $options): self
    {
        return $this;
    }

    public function request(string $method, string $url, array $options = []): FakeEnomResponse
    {
        $this->calls[] = [$method, $url, $options];
        $body = array_shift($this->bodies);
        if (!is_string($body)) {
            throw new RuntimeException('no fake Enom body left');
        }

        return new FakeEnomResponse($body);
    }
}
