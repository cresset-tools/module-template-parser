<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Ast;

final class RootNode implements Node
{
    /** @param Node[] $children */
    public function __construct(
        private readonly array $children = [],
        private readonly string $source = ''
    ) {
    }

    public function source(): string
    {
        return $this->source;
    }

    /** @return Node[] */
    public function children(): array
    {
        return $this->children;
    }

    public function raw(): string
    {
        $out = '';
        foreach ($this->children as $child) {
            $out .= $child instanceof DirectiveNode ? $child->fullRaw() : $child->raw();
        }
        return $out;
    }
}
