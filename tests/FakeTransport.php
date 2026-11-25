<?php

declare(strict_types=1);

namespace PigOpencodeGoUsage\Test;

use Generator;
use Pig\Ai\Http\HttpTransport;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Response;
use Pig\Ai\Http\ResponseBody;
use Pig\Async\AbortSignal;

/** Answers every request with the next canned response, and keeps what was asked. */
final class FakeTransport implements HttpTransport
{
    /** @var list<Request> */
    public array $requests = [];

    /** @param list<array{0: int, 1: string, 2?: array<string, string>}> $answers status, body, headers */
    public function __construct(private array $answers)
    {
    }

    /** One answer, given every time. */
    public static function always(int $status, string $body, array $headers = []): self
    {
        return new self(array_fill(0, 10, [$status, $body, $headers]));
    }

    #[\Override]
    public function send(Request $request, ?AbortSignal $signal = null): Response
    {
        $this->requests[] = $request;
        [$status, $body, $headers] = array_shift($this->answers) + [2 => []];

        return new Response($status, 'Canned', array_change_key_case($headers), new class ($body) extends ResponseBody {
            public function __construct(private readonly string $body)
            {
            }

            #[\Override]
            public function getIterator(): Generator
            {
                yield $this->body;
            }

            #[\Override]
            public function close(): void
            {
            }
        });
    }
}
