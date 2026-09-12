<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento;

use Cresset\TemplateParser\PathGuard;
use Cresset\TemplateParser\Port\TemplateUrlBuilder;

/**
 * `{{var this.getUrl($store,'customer/account/',[_nosid:1])}}`, as legacy resolves it.
 *
 * The receiver check is legacy's, unchanged: StrictResolver invokes getUrl() only when the
 * object it is standing on is an AbstractTemplate, and this returns null - declining, so the
 * call falls back to the ordinary getData() mapping - for anything else. That check is the
 * whole guard on the one place template text reaches a host method with its own arguments,
 * so it stays narrow.
 *
 * The route is guarded on top of that. Legacy concatenates it into a URL unchecked; this
 * gives it the same check {{store url=}} gets, because a route out of template text is a
 * route out of template text however it was written.
 */
class TemplateModelUrlBuilder implements TemplateUrlBuilder
{
    public function urlFor(object $target, array $arguments): ?string
    {
        if (!$target instanceof \Magento\Email\Model\AbstractTemplate) {
            return null;
        }

        $store = $arguments[0] ?? null;
        $route = is_string($arguments[1] ?? null) ? $arguments[1] : '';
        $parameters = is_array($arguments[2] ?? null) ? $arguments[2] : [];

        // '' rather than null from here on: the receiver IS a template model, so the call
        // was served. Declining now would send it back to getData('url'), which is a
        // different answer to the same question.
        if (!$store instanceof \Magento\Store\Model\Store || !PathGuard::isSafeRelativePath($route)) {
            return '';
        }

        // The route is not the only thing that reaches a URL. Url::getRouteUrl() returns
        // `getBaseUrl() . $routeParams['_direct']` with no filtering at all, so `_direct` is a
        // second route wearing a different name - and every OTHER parameter is appended by
        // Url::_getRouteParams() as `$key . '/' . $value . '/'`, key included.
        //
        // This is the same sink {{store}} reaches, so it gets the same guard rather than its
        // own. A list of parameter NAMES cannot do the job here: the set is open, so
        // `[x:'../../..']` walks past any such list. One guard, called twice.
        if (!PathGuard::routeParametersAreSafe($parameters)) {
            return '';
        }

        try {
            return (string)$target->getUrl($store, $route, $parameters);
        } catch (\Throwable) {
            return '';
        }
    }
}
