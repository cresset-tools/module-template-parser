<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Console\Source;

/**
 * A source that looked readable failed while it was being read.
 *
 * Thrown rather than swallowed: a database that went away mid-run used to read as a source
 * with no templates in it, and a scan of nothing reported as a clean pass.
 */
final class SourceUnavailable extends \RuntimeException
{
}
