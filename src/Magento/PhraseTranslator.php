<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento;

use Cresset\TemplateParser\Port\Translator;

/**
 * {{trans}} via Magento's translation layer.
 */
class PhraseTranslator implements Translator
{
    /** @param array<string,string> $arguments keyed by placeholder, `%` included */
    public function translate(string $text, array $arguments): string
    {
        // __($text) alone, then substitute: passing the arguments to __() would run them
        // through Phrase\Renderer\Placeholder, which expects the raw argument keys and
        // would apply its integer-key numbering a second time.
        $translated = (string)__($text);

        // One pass, not a str_replace per argument. Substituting in sequence has two faults:
        // `%name` rewrites the front of `%name_long` before its own turn comes, and a value
        // containing `%b` becomes a live placeholder for a later argument - a variable's
        // value turning back into template syntax. strtr() takes the longest matching key at
        // each position and never re-scans what it has written. It is also what Magento's
        // own Placeholder renderer does.
        return $arguments === [] ? $translated : strtr($translated, $arguments);
    }
}
