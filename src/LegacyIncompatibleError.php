<?php
declare(strict_types=1);

namespace MageOS\TemplateParser;

/**
 * Raised instead of rendering, when Options::$refuseLegacyIncompatible is set.
 *
 * For operators who want compatible mode to be exactly as capable as legacy - no more - so
 * that a template which would fail on the old engine also fails here.
 */
final class LegacyIncompatibleError extends TemplateError
{
}
