<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Ast;

final class DirectiveNode implements Node
{
    /**
     * @param Node[] $children     body of a block directive
     * @param Node[]|null $alternate  {{else}} branch, when present
     */
    public function __construct(
        private readonly string $name,
        private readonly string $params,
        private readonly string $raw,
        private readonly int $offset = 0,
        private array $children = [],
        private ?array $alternate = null,
        private string $closingRaw = ''
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function params(): string
    {
        return $this->params;
    }

    /** @return Node[] */
    public function children(): array
    {
        return $this->children;
    }

    /** @return Node[]|null */
    public function alternate(): ?array
    {
        return $this->alternate;
    }

    public function hasAlternate(): bool
    {
        return $this->alternate !== null;
    }

    /** @param Node[] $children */
    public function setChildren(array $children): void
    {
        $this->children = $children;
    }

    /** @param Node[] $alternate */
    public function setAlternate(array $alternate): void
    {
        $this->alternate = $alternate;
    }

    public function setClosingRaw(string $raw): void
    {
        $this->closingRaw = $raw;
    }

    public function offset(): int
    {
        return $this->offset;
    }

    public function raw(): string
    {
        return $this->raw;
    }

    /**
     * Full source span, including body and closing tag. Used to render an unknown or
     * unevaluated directive back exactly as written.
     */
    public function fullRaw(): string
    {
        $out = $this->raw;
        foreach ($this->children as $child) {
            $out .= $child instanceof self ? $child->fullRaw() : $child->raw();
        }
        if ($this->alternate !== null) {
            $out .= '{{else}}';
            foreach ($this->alternate as $child) {
                $out .= $child instanceof self ? $child->fullRaw() : $child->raw();
            }
        }
        return $out . $this->closingRaw;
    }
}
