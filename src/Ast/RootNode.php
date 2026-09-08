<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Ast;

final class RootNode implements Node
{
    /** @param Node[] $children */
    public function __construct(
        private readonly array $children = [],
        private readonly string $source = '',
        private readonly array $incompatibilities = []
    ) {
    }

    /** @return \MageOS\TemplateParser\LegacyIncompatibility[] */
    public function incompatibilities(): array
    {
        return $this->incompatibilities;
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
