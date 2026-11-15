<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Port;

/**
 * Loads a child template by config path, for {{template config_path="..."}}.
 *
 * Returns source only. The caller parses and evaluates it in a child scope and absorbs the
 * child's deferred work, so nothing has to travel back as text.
 */
interface TemplateLoader
{
    public function load(string $configPath): ?string;
}
