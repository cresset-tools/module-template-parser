<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento;

use Cresset\TemplateParser\Port\Translator;

/**
 * {{trans}} via Magento's translation layer.
 */
final class PhraseTranslator implements Translator
{
    /** @param array<string,string> $arguments */
    public function translate(string $text, array $arguments): string
    {
        $translated = (string)__($text);
        foreach ($arguments as $key => $value) {
            $translated = str_replace('%' . $key, $value, $translated);
        }
        return $translated;
    }
}
