<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console;

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
     */
    public function __construct(
        public readonly string $output,
        public readonly array $variables,
        public readonly ?\Closure $finish = null,
    ) {
    }
}
