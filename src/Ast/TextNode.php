<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Ast;

final class TextNode implements Node
{
    /**
     * @param int|null $offset where the text starts in the source, when the parser knows it.
     *        Only the parser's own text nodes carry one, and only for diagnostics.
     */
    public function __construct(
        private readonly string $text,
        private readonly ?int $offset = null
    ) {
    }

    public function text(): string
    {
        return $this->text;
    }

    public function raw(): string
    {
        return $this->text;
    }

    public function offset(): ?int
    {
        return $this->offset;
    }
}
