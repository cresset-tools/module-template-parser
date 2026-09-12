<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Port;

/**
 * Renders the directives a host has registered that this engine does not implement itself.
 *
 * Magento's `SimpleDirective\ProcessorPool` lets a module add a NAMED directive, so a store
 * with such a module renders `{{mydir "v" p=1}}body{{/mydir}}` and one without does not. The
 * engine cannot know the surface in advance, so it asks.
 *
 * The directive's shape is the host's, not this engine's: an optional quoted value, ordinary
 * parameters, an optional body, and its own modifiers. The body arrives ALREADY RENDERED,
 * because the filter renders it too - `{{mydir}}{{var x}}{{/mydir}}` passes the resolved
 * variable to the processor, not the directive text.
 */
interface CustomDirectiveRenderer
{
    /**
     * The directive names this host can render.
     *
     * Read once, when an engine is built: they decide what the parser treats as a directive
     * at all, so they cannot change mid-render.
     *
     * @return string[]
     */
    public function names(): array;

    /**
     * @param ?string $value the quoted value, as written - the host decides what it means
     * @param array<string,string|null> $parameters with `$name` values already resolved
     * @param ?string $body the rendered body, or null when the directive was not paired
     * @param string[] $modifiers the modifiers the TEMPLATE named, which may be empty
     *
     * Applying the modifiers is the host's job, and deliberately so. They come from the same
     * registry the directive does - Magento's FilterPool - and its rule is that a template
     * naming any modifier SUPPRESSES the processor's own defaults, so `{{mydir "v"|raw}}`
     * applies nothing at all rather than `raw`. That rule belongs where the registry is.
     */
    public function render(
        string $name,
        ?string $value,
        array $parameters,
        ?string $body,
        array $modifiers
    ): ?string;
}
