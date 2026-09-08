<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Port;

/**
 * Renders a widget for {{widget type="..."}}.
 *
 * Same discipline as BlockRenderer: validate the type BEFORE instantiating it. A widget type
 * from template text is attacker-influenced wherever the template is.
 */
interface WidgetRenderer
{
    public function render(string $type, array $parameters): string;
}
