<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

use Cresset\TemplateParser\Ast\DirectiveNode;
use Cresset\TemplateParser\Ast\Node;

/**
 * A block directive that was never closed. Carries its source so the evaluator can emit it
 * verbatim: an unbalanced construct is text, never a directive to execute.
 */
final class UnclosedDirective implements Node
{
    public function __construct(private readonly DirectiveNode $inner)
    {
    }

    public function raw(): string
    {
        return $this->inner->fullRaw();
    }
}
