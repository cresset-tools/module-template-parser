<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

/**
 * A variable expression that does not resolve in the current scope.
 */
final class UnknownVariableError extends TemplateError
{
}
