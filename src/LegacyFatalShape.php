<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

/**
 * A resolution shape that raises inside the legacy filter.
 *
 * The resolver has no view of the template source and no access to the render options, so it
 * signals rather than decides: the evaluator catches this and routes it through
 * noteLegacyIncompatible(), which knows whether compatible mode is refusing or recording.
 */
final class LegacyFatalShape extends \RuntimeException
{
}
