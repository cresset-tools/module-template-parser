<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Ast;

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
        private string $alternateRaw = '{{else}}',
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

    /**
     * @param Node[] $alternate
     * @param string $raw the {{else}} tag exactly as written, so fullRaw() stays lossless
     */
    public function setAlternate(array $alternate, string $raw = '{{else}}'): void
    {
        $this->alternate = $alternate;
        $this->alternateRaw = $raw;
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
            // The tag as written, not a canonical one: {{ELSE}} reaches here (a padded
            // {{else }} does not - splitOnElse() reports it as a typo), and rewriting it
            // would make fullRaw() lossy for the one construct whose whole job is to hand a
            // directive back exactly as the author typed it.
            $out .= $this->alternateRaw;
            foreach ($this->alternate as $child) {
                $out .= $child instanceof self ? $child->fullRaw() : $child->raw();
            }
        }
        return $out . $this->closingRaw;
    }
}
