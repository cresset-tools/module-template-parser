<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Testing;

/**
 * Stands in for whatever the host threw when the tape was recorded.
 *
 * Only the class name survives a tape, which is enough: what the engine does with a host
 * failure - let it out, or catch it and render a gap - does not depend on which failure it was.
 */
final class ReplayedPortFailure extends \RuntimeException
{
    public function __construct(public readonly string $originalClass)
    {
        parent::__construct(sprintf('the host threw %s here when this tape was recorded', $originalClass));
    }
}
