<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

/**
 * A template nested deeper than a limit allows: block directives past
 * Options::$maxNestingDepth, or method arguments past the resolver's fixed
 * MAX_ARGUMENT_DEPTH, which no option raises.
 *
 * This is a bound on input complexity rather than a strictness setting, so it applies in
 * lenient mode too: a template that nests 40 deep is not content to be recovered, it is
 * input to be refused before it becomes a stack-depth problem.
 */
final class NestingLimitError extends TemplateError
{
}
