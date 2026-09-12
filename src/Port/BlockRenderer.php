<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Port;

/**
 * Renders a {{block}} directive.
 *
 * Implementations MUST validate the requested type *before* instantiating it, and MUST
 * constrain which method the template may invoke. Both are the lessons of the
 * check-after-construct bugs in BlockFactory and UrlGeneratorFactory: by the time you can
 * test the instance, an arbitrary constructor has already run.
 */
interface BlockRenderer
{
    /** @param array<string,string|null> $data */
    public function render(string $class, array $data, string $method): string;
}
