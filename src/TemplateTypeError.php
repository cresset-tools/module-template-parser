<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

/**
 * A variable that resolves, but not to the shape the directive needs — for example
 * {{for x in y}} where y is a scalar.
 */
final class TemplateTypeError extends TemplateError
{
}
