<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Ast;

interface Node
{
    /** Exact source text this node came from. */
    public function raw(): string;
}
