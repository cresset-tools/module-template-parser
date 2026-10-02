<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Port;

/**
 * A UrlBuilder that can also answer for a store other than the current one.
 *
 * `{{protocol store="2"}}` asks for the scheme of store 2, and the filter answers with that
 * store's. A builder that cannot is still a UrlBuilder; {{protocol store=}} is then declined
 * rather than answered for the wrong store.
 */
interface StoreAwareUrlBuilder extends UrlBuilder
{
    /**
     * Whether the named store - an id or a code - is served securely right now. Throws
     * RefusedByPort for a store that does not exist, where the filter raises.
     */
    public function isSecureFor(string $store): bool;
}
