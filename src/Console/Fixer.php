<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console;

use Cresset\TemplateParser\LegacyIncompatibleError;
use Cresset\TemplateParser\NestingLimitError;
use Cresset\TemplateParser\SyntaxError;
use Cresset\TemplateParser\TemplateError;
use Cresset\TemplateParser\UnknownDirectiveError;
use Cresset\TemplateParser\UnknownVariableError;

/**
 * Turns an engine error into something a merchant can act on.
 *
 * The engine's own messages are written for whoever is editing the template and already say
 * where and what. What they do not say is what to type instead, because the engine has no
 * business guessing. Here that guess is welcome: this is a tool someone runs precisely
 * because they want to be told what to change.
 */
class Fixer
{
    /** @return array{0:string,1:?string} severity and suggested fix */
    public function advise(TemplateError $error): array
    {
        return match (true) {
            $error instanceof UnknownVariableError => [
                Finding::WARNING,
                'The template reads a variable nothing provides. Check the spelling, or drop the '
                . 'directive if the value is no longer sent. On the legacy filter this silently '
                . 'rendered nothing, which is why it can sit unnoticed for years.',
            ],
            $error instanceof UnknownDirectiveError => [
                Finding::ERROR,
                'No handler is wired for this directive. Inside a store that usually means the '
                . 'module providing it is disabled; outside one it just means the tool has no '
                . 'port for it. Run this against your store to be sure.',
            ],
            $error instanceof NestingLimitError => [
                Finding::ERROR,
                'Directives are nested deeper than the render allows. Raise it with '
                . 'Options::withMaxNestingDepth() if the template is genuinely that shape, or '
                . 'flatten it - deep nesting in an email is usually a copy-paste accident.',
            ],
            $error instanceof LegacyIncompatibleError => [
                Finding::NOTE,
                'This construct does not render on the legacy filter either - compatible mode '
                . 'refuses it rather than inventing behaviour. It is safe to fix now: whatever '
                . 'it was meant to do, it has never done it.',
            ],
            $error instanceof SyntaxError => [
                Finding::ERROR,
                'The template does not parse. The usual causes are an unclosed {{if}} or '
                . '{{depend}}, or a closing tag whose name does not match the one it closes.',
            ],
            default => [Finding::ERROR, null],
        };
    }
}
