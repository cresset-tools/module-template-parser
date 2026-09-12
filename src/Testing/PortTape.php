<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Testing;

/**
 * A record of every question the engine asked its ports, and the answer it got.
 *
 * The port boundary is where this engine's responsibility ends: above it are the guards and
 * the parameter handling, below it is the host's own code. So the tape is exactly the
 * engine's observable decisions - which values it let through, which it refused by never
 * asking at all, and what it forwarded alongside them.
 *
 * That matters because the ports need a store and the fixtures must not. Recording the tape
 * against a real store and replaying it without one is what lets the twelve port-backed
 * directives be covered by the suite at all; before this they were excluded from the corpus
 * by construction, and every security bug adversarial fuzzing has found in this package
 * lived in that gap.
 */
final class PortTape
{
    /** @var list<array{port:string,method:string,args:array<int,mixed>,returned?:mixed,threw?:string}> */
    private array $entries = [];

    private int $position = 0;

    /** @param array<int,mixed> $args */
    public function record(string $port, string $method, array $args, mixed $returned): mixed
    {
        $this->entries[] = ['port' => $port, 'method' => $method, 'args' => $args, 'returned' => $returned];

        return $returned;
    }

    /**
     * A call that raised. The class is recorded, not the object: a tape must survive JSON.
     *
     * @param array<int,mixed> $args
     */
    public function recordThrow(string $port, string $method, array $args, \Throwable $e): void
    {
        $this->entries[] = [
            'port' => $port,
            'method' => $method,
            'args' => $args,
            'threw' => (new \ReflectionClass($e))->getShortName(),
        ];
    }

    /**
     * The answer this call got when the tape was recorded.
     *
     * Strictly in order, and strictly matching: a replay that asks a different question, or
     * the same questions in a different order, is an engine that has changed its mind about
     * what to let through. That is the whole point of the tape, so it fails rather than
     * falling back to something plausible.
     *
     * @param array<int,mixed> $args
     */
    public function replay(string $port, string $method, array $args): mixed
    {
        $expected = $this->entries[$this->position] ?? null;

        if ($expected === null) {
            throw new UnexpectedPortCall(sprintf(
                'the engine made an extra call the tape does not have: %s::%s(%s)',
                $port,
                $method,
                self::describe($args)
            ));
        }

        if ($expected['port'] !== $port || $expected['method'] !== $method || $expected['args'] !== $args) {
            throw new UnexpectedPortCall(sprintf(
                "call %d differs from the tape\n  recorded: %s::%s(%s)\n  now:      %s::%s(%s)",
                $this->position + 1,
                $expected['port'],
                $expected['method'],
                self::describe($expected['args']),
                $port,
                $method,
                self::describe($args)
            ));
        }

        $this->position++;

        if (isset($expected['threw'])) {
            throw new ReplayedPortFailure($expected['threw']);
        }

        return $expected['returned'];
    }

    /** Calls the tape holds that the replay never made - a guard that started refusing. */
    public function unplayed(): array
    {
        return array_slice($this->entries, $this->position);
    }

    /** @return list<array{port:string,method:string,args:array<int,mixed>,returned?:mixed,threw?:string}> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @param list<array{port:string,method:string,args:array<int,mixed>,returned?:mixed,threw?:string}> $entries */
    public static function fromEntries(array $entries): self
    {
        $tape = new self();
        $tape->entries = $entries;

        return $tape;
    }

    /** @param array<int,mixed> $args */
    public static function describe(array $args): string
    {
        return implode(', ', array_map(
            static fn (mixed $a): string => is_scalar($a) || $a === null
                ? var_export($a, true)
                : json_encode($a, JSON_UNESCAPED_SLASHES),
            $args
        ));
    }
}
