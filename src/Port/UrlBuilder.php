<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Port;

/**
 * Builds the URLs the {{store}}, {{media}}, {{view}} and {{protocol}} directives produce.
 *
 * Every path reaching these has already been checked by PathGuard, so an implementation can
 * assume no traversal, no scheme and no absolute path - but should still resolve only within
 * its own roots.
 */
interface UrlBuilder
{
    /** @param array<string,string> $parameters */
    public function storeUrl(string $path, array $parameters): string;

    public function mediaUrl(string $path): string;

    /** @param array<string,string> $parameters */
    public function viewUrl(string $path, array $parameters): string;

    /** Whether the current store is being served over HTTPS, for {{protocol}}. */
    public function isSecure(): bool;
}
