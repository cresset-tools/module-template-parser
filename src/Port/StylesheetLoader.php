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
    /**
     * @param array<string,mixed> $designParams the area, theme and locale to resolve against
     *
     * Passed rather than looked up, because WHEN a stylesheet is resolved decides what a live
     * lookup sees. `Email\Model\Template\Filter` carries a snapshot the template model handed
     * it inside the model's own emulation, and `getProcessedTemplate()` cancels that emulation
     * before `filter()` is called - so an implementation reading the design at render time gets
     * a different theme from the one the filter used, and the same template resolves a
     * different stylesheet depending on who called it and when. An empty array means the caller
     * has no design to offer and the implementation should fall back to its own.
     */
    public function load(string $file, array $designParams = []): ?string;
}
