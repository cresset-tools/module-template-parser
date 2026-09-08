<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Lexer;

enum TokenType
{
    case Text;
    case DirectiveOpen;   // {{name params}}
    case DirectiveClose;  // {{/name}}
}

/**
 * A lexical token. `raw` always holds the exact source text, so any token can be
 * rendered back verbatim - which is how unknown constructs stay inert.
 */
final class Token
{
    public function __construct(
        public readonly TokenType $type,
        public readonly string $raw,
        public readonly int $offset,
        public readonly string $name = '',
        public readonly string $params = ''
    ) {
    }
}
