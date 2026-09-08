<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Port;

interface Translator
{
    /** @param array<string,string> $arguments */
    public function translate(string $text, array $arguments): string;
}
