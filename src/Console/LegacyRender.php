<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Console;

/**
 * What the store's current filter made of a template, and what it had to work with.
 *
 * The variables matter as much as the output. Magento does not hand a filter the variables
 * the caller passed: an email template model adds `store`, `logo_url`, `store_phone` and a
 * dozen more, and puts the template model itself in `this`. Comparing a legacy render that
 * had those against one that did not measures the harness, not the engine - so the set that
 * was actually used travels back with the output and both sides render from it.
 */
class LegacyRender
{
    /**
     * @param array<string,mixed> $variables
     * @param ?\Closure(string):string $finish the host's post-filter step, if it has one
     * @param array<string,mixed> $designParams the area, theme and locale the filter resolved
     *        {{css}} against. Travels back for the same reason the variables do: nothing
     *        downstream can look them up for itself afterwards - see
     *        Port\StylesheetLoader::load().
     * @param ?\Closure(callable(string):string):string $through runs a candidate render through
     *        the same filter's plugin chain, where a Parser render would run - so the plugins
     *        on filter() that changed `$output` change the candidate too. Given the render,
     *        called with the template as the plugins before this module's left it.
     * @param ?string $failure set when `$output` is the filter's error message in place of
     *        the template - the Email filter catches what the render raised and prints
     *        "Error filtering template: ..." (developer mode) or "We're sorry, an error has
     *        occurred while generating this content." - so legacy did not render this
     */
    public function __construct(
        public readonly string $output,
        public readonly array $variables,
        public readonly ?\Closure $finish = null,
        public readonly array $designParams = [],
        public readonly ?\Closure $through = null,
        public readonly ?string $failure = null,
    ) {
    }
}
