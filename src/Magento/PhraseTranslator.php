<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Magento;

use Magento\Framework\Translate\Inline\StateInterface;
use MageOS\TemplateParser\Port\Translator;

/**
 * {{trans}} via Magento's translation layer, with inline translation off for the call.
 *
 * transDirective disables Translate Inline around its own `__()`: with it on, the phrase
 * comes back wrapped in `{{{text}}{{text}}{{text}}}` editing markers, which in a rendered page
 * or an email are visible text. Suspended and resumed rather than disabled and enabled, so
 * a caller that had it off - an email being sent - still has it off afterwards.
 */
class PhraseTranslator implements Translator
{
    public function __construct(private readonly ?StateInterface $inlineTranslation = null)
    {
    }

    /** @param array<string,string> $arguments keyed by placeholder, `%` included */
    public function translate(string $text, array $arguments): string
    {
        // __($text) alone, then substitute: passing the arguments to __() would run them
        // through Phrase\Renderer\Placeholder, which expects the raw argument keys and
        // would apply its integer-key numbering a second time.
        $this->inlineTranslation?->suspend();
        try {
            $translated = (string)__($text);
        } finally {
            $this->inlineTranslation?->resume();
        }

        // strtr(), for the reasons the no-host translator in Evaluator gives - and because
        // it is what Phrase\Renderer\Placeholder does, so this port substitutes the way the
        // layer it stands in front of would have.
        return $arguments === [] ? $translated : strtr($translated, $arguments);
    }
}
