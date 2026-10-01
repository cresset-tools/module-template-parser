<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Port;

/**
 * Thrown by a port to decline a request on policy grounds rather than fail it.
 *
 * The directive that asked records it as a policy violation and renders nothing, which is
 * what a render policy refusal does too. The difference from returning '' is that the
 * refusal is SEEN: Shadow reports it, `check` turns it into a finding, and Parser mode hands
 * the render to the legacy filter instead of serving a page with a hole in it. A silent ''
 * from the layout allowlist served every stock order email without its item table.
 */
final class RefusedByPort extends \RuntimeException
{
    public function __construct(public readonly string $kind, public readonly string $name)
    {
        parent::__construct(sprintf('%s "%s" is not permitted', $kind, $name));
    }
}
