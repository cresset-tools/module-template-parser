<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Port;

/**
 * The one method call a template is allowed to make with arguments.
 *
 * Legacy's StrictResolver maps every `getFoo()` to `getData('foo')` and never invokes a real
 * method - with exactly one exception, carved out for the stock templates:
 *
 *     if ($stackArgs[$i]['name'] === 'getUrl' && $stackArgs[$i - 1]['variable'] instanceof AbstractTemplate)
 *
 * That is where every "log into your account" link in every stock Magento email comes from,
 * so an engine without it renders `href=""` on most of them. It is a hole in an otherwise
 * closed surface, so it is a port: a host that wants those links declares this, and a host
 * that does not gets the ordinary getData() mapping and no host call at all.
 */
interface TemplateUrlBuilder
{
    /**
     * The URL `getUrl(...)` stands for, or null when $target is not a model this serves.
     *
     * Returning null is the refusal, and it is what keeps the hole the size legacy made it:
     * the arguments came out of template text, so an implementation must satisfy itself
     * about the receiver before it calls anything.
     *
     * @param list<mixed> $arguments already resolved - a `$name` argument holds its value
     */
    public function urlFor(object $target, array $arguments): ?string;
}
