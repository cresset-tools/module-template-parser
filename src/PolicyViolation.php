<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

/**
 * A template asked for something the render policy does not permit.
 *
 * Recorded rather than thrown by default: a policy violation should not take down an order
 * email, but it must not pass unnoticed either. Set Options::$failOnPolicyViolation to make
 * it fatal instead.
 */
final class PolicyViolation
{
    public const DIRECTIVE = 'directive';
    public const BLOCK = 'block';

    public function __construct(
        public readonly string $kind,
        public readonly string $name,
        public readonly int $line,
        public readonly int $column
    ) {
    }

    public function describe(): string
    {
        return sprintf(
            'policy refused %s "%s" (line %d, column %d)',
            $this->kind,
            $this->name,
            $this->line,
            $this->column
        );
    }
}
