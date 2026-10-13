<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Lexer;

enum TokenType
{
    case Text;
    case DirectiveOpen;   // {{name params}}
    case DirectiveClose;  // {{/name}}
    /**
     * A {{...}} whose first inner character is not a letter.
     *
     * Not a directive here - but legacy's CONSTRUCTION_PATTERN is case-insensitive, so it
     * captures a name for anything starting with a letter and falls back cleanly. When the
     * first character is a digit, space, slash or punctuation it captures nothing, and
     * SimpleDirective is handed a null directiveName: a TypeError. Tracked separately so
     * compatible mode can refuse exactly what legacy cannot render.
     */
    case Degenerate;
}
