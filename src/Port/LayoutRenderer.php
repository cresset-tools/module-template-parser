<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Port;

/**
 * Renders a layout handle for {{layout handle="..."}}.
 *
 * Implementations SHOULD restrict which handles a template may name; a handle is a
 * capability, and template text is not a trustworthy source for one.
 */
interface LayoutRenderer
{
    /** @param array<string,string|null> $parameters */
    public function render(string $handle, string $area, array $parameters): string;
}
