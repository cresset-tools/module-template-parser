<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Testing;

/** Records through to a live port, or answers from the tape when there is none. */
trait TapedPort
{
    public function __construct(private PortTape $tape, private ?object $inner = null)
    {
    }

    /**
     * @param array<int,mixed> $args what goes on the tape
     * @param array<int,mixed>|null $liveArgs what the live port is called with, when it differs
     */
    private function call(string $port, string $method, array $args, ?array $liveArgs = null): mixed
    {
        if ($this->inner === null) {
            return $this->tape->replay($port, $method, $args);
        }

        // A port that RAISES is a decision too, and the loudest one: it is how a host error
        // reaches - or escapes - the engine. Recording only successful calls left the most
        // interesting cases with an empty tape, which read as "the guard refused" when what
        // actually happened was the opposite.
        try {
            $returned = $this->inner->{$method}(...($liveArgs ?? $args));
        } catch (\Throwable $e) {
            $this->tape->recordThrow($port, $method, $args, $e);
            throw $e;
        }

        return $this->tape->record($port, $method, $args, $returned);
    }
}
