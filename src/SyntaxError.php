<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

/**
 * A directive that could not be parsed: unclosed, unbalanced or malformed.
 */
final class SyntaxError extends TemplateError
{
}
