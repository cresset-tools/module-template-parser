<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Port;

interface Translator
{
    /**
     * Translates $text and substitutes its placeholders.
     *
     * $arguments is keyed by the placeholder itself, `%` included and already resolved -
     * `['%store_name' => 'Acme']`. Magento's own numbering rule (an integer argument key
     * stands for the NEXT placeholder up) is applied before the call, so an implementation
     * has nothing to work out: substitute each key with its value, once, and return.
     *
     * @param array<string,string> $arguments
     */
    public function translate(string $text, array $arguments): string;
}
