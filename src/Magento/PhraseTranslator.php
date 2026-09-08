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
        if ($arguments === []) {
            return $translated;
        }

        // One pass, not a str_replace per argument. Substituting in sequence has two faults:
        // `%name` rewrites the front of `%name_long` before its own turn comes, and a value
        // containing `%b` becomes a live placeholder for a later argument - a variable's
        // value turning back into template syntax. strtr() takes the longest matching key at
        // each position and never re-scans what it has written.
        $map = [];
        foreach ($arguments as $key => $value) {
            $map['%' . $key] = $value;
        }

        return strtr($translated, $map);
    }
}
