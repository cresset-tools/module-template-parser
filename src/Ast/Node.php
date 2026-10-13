<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Ast;

interface Node
{
    /** Exact source text this node came from. */
    public function raw(): string;
}
