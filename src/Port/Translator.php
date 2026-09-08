<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Port;

interface Translator
{
    /** @param array<string,string> $arguments */
    public function translate(string $text, array $arguments): string;
}
