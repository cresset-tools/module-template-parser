<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Port;

/**
 * Loads and processes a stylesheet for {{css file="..."}}.
 *
 * The handler validates the path before calling this, but an implementation should still
 * resolve only within its own asset roots.
 */
interface StylesheetLoader
{
    public function load(string $file): ?string;
}
