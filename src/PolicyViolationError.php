<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

/** Raised instead of recording, when Options::$failOnPolicyViolation is set. */
final class PolicyViolationError extends TemplateError
{
}
