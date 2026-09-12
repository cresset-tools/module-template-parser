<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Port;

/**
 * Builds the URLs the {{store}}, {{media}}, {{view}} and {{protocol}} directives produce.
 *
 * $path and every other parameter have been through PathGuard - the keys as well as the
 * values, because Url appends a surviving route parameter as `key/value/`. So nothing with a
 * traversal, a scheme or an absolute prefix reaches the path here, though an implementation
 * should still resolve only within its own roots.
 *
 * Two kinds are held to no path shape at all: `_query_*`, which carries whatever a customer
 * typed, and the flags and scopes routeParametersAreSafe() skips. Keeping those out of the
 * path is the implementation's job - StoreUrlBuilder folds `_query_*` into `_query` before
 * handing the array to the URL model.
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
