<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

/**
 * A host accessor reached from a template raised an exception.
 *
 * The resolver has no view of the template source, so it raises this unpositioned and the
 * evaluator re-raises it against the directive it was resolving. Reported as a TemplateError
 * rather than allowed to propagate so a template cannot take the render down with a host
 * exception, or surface an internal message through one.
 */
final class AccessorError extends TemplateError
{
}
