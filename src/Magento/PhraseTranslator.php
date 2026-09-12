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

        // strtr(), for the reasons the no-host translator in Evaluator gives - and because
        // it is what Phrase\Renderer\Placeholder does, so this port substitutes the way the
        // layer it stands in front of would have.
        return $arguments === [] ? $translated : strtr($translated, $arguments);
    }
}
