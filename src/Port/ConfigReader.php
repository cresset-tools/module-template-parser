<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Port;

/**
 * Reads store configuration for {{config path="..."}}.
 *
 * Implementations MUST enforce an allowlist. Magento gates this with
 * Variable\Model\Source\Variables::getAvailableVars(); without that gate a template can
 * read any configuration value the store has.
 */
interface ConfigReader
{
    public function value(string $path): ?string;
}
