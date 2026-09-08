<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

/**
 * A {{template}} include that is already being rendered further up the stack.
 *
 * Without this the include recurses until the process runs out of stack.
 */
final class TemplateCycleError extends TemplateError
{
}
