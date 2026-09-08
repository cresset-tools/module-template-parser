<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Ast;

final class TextNode implements Node
{
    public function __construct(private readonly string $text)
    {
    }

    public function text(): string
    {
        return $this->text;
    }

    public function raw(): string
    {
        return $this->text;
    }
}
