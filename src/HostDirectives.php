<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

use Cresset\TemplateParser\Ast\DirectiveNode;
use Cresset\TemplateParser\Ast\DirectiveNode as Node;

/**
 * Registers the directives that need something from the host application.
 *
 * Everything here is opt-in: a directive with no port supplied stays unregistered, which
 * means it is reported in strict mode and rendered verbatim in lenient mode. Nothing is
 * ever dispatched by reflection.
 */
final class HostDirectives
{
/**
 * Guards take the value as written and PathGuard does the decoding.
 *
 * It decodes percent-escapes AND HTML entities, repeatedly, because a browser will:
 * decoding once here instead made the guard see `&#46;&#46;/x` as safe while the browser
 * still saw `../x`, so a value encoded twice walked through. Doing it inside the guard
 * also means the ORIGINAL value is what reaches the port, which is what legacy emits -
 * `a&amp;b` used to arrive as `a&b`.
 */
    /**
     * Parameters forwarded to a UrlBuilder that Magento treats as path fragments.
     *
     * `_direct` is the one that matters: Url::getRouteUrl() concatenates it onto the base
     * URL unfiltered. The rest are checked because they are forwarded verbatim and an
     * implementation is entitled to assume the handler already looked.
     *
     * @param array<string,string> $parameters
     */
    /** Parameters that are flags, scopes or query carriers rather than path segments. */
    private const NON_PATH_PARAMETERS = [
        '_query', '_nosid', '_absolute', '_secure', '_escape_params', '_scope', '_scope_to_url',
        '_type',
    ];

    /** @var array<string,string> */
    private const DESIGN_PARAMETER_SHAPES = [
        'area' => '/^[a-zA-Z0-9_]{1,64}$/',
        'locale' => '/^[a-zA-Z0-9_-]{1,32}$/',
        'module' => '/^[a-zA-Z0-9_]{1,128}$/',
        'theme' => '#^[a-zA-Z0-9_]{1,64}/[a-zA-Z0-9_-]{1,64}$#',
        'themeId' => '/^[0-9]{1,10}$/',
    ];

    private static function pathParametersAreSafe(array $parameters): bool
    {
        // `_type` picks which base URL the result is built on, so it is a name from a fixed
        // set (link, web, media, static) and not free text - it is skipped by the loop below
        // as a flag, which would otherwise leave it the one unguarded spelling.
        $type = $parameters['_type'] ?? null;
        if (is_string($type) && $type !== '' && !preg_match('/^[a-z]{1,16}$/', $type)) {
            return false;
        }

        foreach ($parameters as $key => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }

            // A `_query_x` parameter becomes a QUERY parameter - storeDirective moves it into
            // `_query` and the URL model escapes it - so it never reaches the path, and
            // holding it to a path guard would refuse an ordinary customer name with an
            // apostrophe in it. The rest of this list is flags and scopes.
            if (str_starts_with($key, '_query_') || in_array($key, self::NON_PATH_PARAMETERS, true)) {
                continue;
            }

            // Everything else is a route parameter, and Url::_getRouteParams() appends those
            // as `$key . '/' . $value . '/'` - so the KEY is a path segment as much as the
            // value is. Naming only `_direct`, `_fragment` and `_escape_params`, as this did,
            // guarded three spellings out of an open set: `{{store url="x" a="../../.."}}`
            // walked straight past it.
            if (!PathGuard::isSafeRelativePath((string)$key) || !PathGuard::isSafeRelativePath($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The design parameters Asset\Repository turns into static-URL path segments.
     *
     * FallbackContext::generatePath() is `$area . '/' . $theme . '/' . $locale` with no
     * validation of any of the three, and `module` becomes a segment of its own, so
     * `{{view url="x" locale="../../.."}}` climbs out of the static root while still reading
     * as a same-origin URL. Each is held to its own shape rather than to a path guard,
     * because none of them is a path: an area and a module are identifiers, a locale is
     * `en_US`, and a theme is exactly `Vendor/name`.
     */
    private static function designParametersAreSafe(array $parameters): bool
    {
        // themeModel is an object everywhere it is legitimately set. A string one is never
        // anything but an attempt, and updateDesignParams hands it straight to
        // Design::getThemePath(), so it is refused rather than shaped.
        if (isset($parameters['themeModel'])) {
            return false;
        }

        foreach (self::DESIGN_PARAMETER_SHAPES as $key => $shape) {
            $value = $parameters[$key] ?? null;
            if ($value !== null && $value !== '' && !preg_match($shape, (string)$value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The variables an included template can see.
     *
     * Legacy hands the processor `array_merge_recursive($directiveParameters,
     * $templateVariables)` with config_path removed - so the directive's own parameters
     * become variables inside the include, on top of everything the parent had. Without this
     * a template written as
     *
     *     {{template config_path="design/email/footer" store_hours="9-5"}}
     *
     * renders the include with store_hours unset.
     *
     * array_merge_recursive is not array_merge: on a key both sides define, it produces an
     * ARRAY of both values rather than letting one win, so a parameter named after an
     * existing variable makes that variable render as "Array". Reproduced in compatible mode
     * only; elsewhere the parameter simply wins, which is what anyone writing one expects.
     *
     * @param array<string,string> $parameters already $-resolved, config_path included
     * @return array<string,mixed>
     */
    private static function includeScope(array $parameters, Context $context, Evaluator $evaluator): array
    {
        unset($parameters['config_path']);
        if ($parameters === []) {
            return [];
        }

        if (!$evaluator->options()->legacyQuirks) {
            return $parameters;
        }

        $scope = [];
        foreach ($parameters as $key => $value) {
            $scope[$key] = $context->has($key) ? [$value, $context->get($key)] : $value;
        }

        return $scope;
    }

    /**
     * What legacy emits for an include it cannot process.
     *
     * TemplateDirective::process returns this literal string when config_path is absent or
     * no template processor is set - it is not an exception and not empty output, it is text
     * that ends up in the email. Compatible mode reproduces it; elsewhere an unresolvable
     * include renders nothing, since the string is a legacy artefact and not useful output.
     */
    private static function unresolvedInclude(Evaluator $evaluator): string
    {
        return $evaluator->options()->legacyQuirks ? '{Error in template processing}' : '';
    }

    public static function register(
        Evaluator $evaluator,
        HostServices $services,
        ?Parser $parser = null
    ): void {
        $blocks = $services->blocks;
        $translator = $services->translator;
        $templates = $services->templates;

        if ($blocks !== null) {
            $evaluator->register('block', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($blocks): string {
                $params = $e->params($n, $c);
                $class = $params['class'] ?? '';
                if ($class === '') {
                    return '';
                }

                // Checked before the port, so a refused class is never constructed.
                if (!$c->policy()->permitsBlock($class)) {
                    $e->refusedByPolicy($n, $c, PolicyViolation::BLOCK, $class);
                    return '';
                }

                $method = $params['output'] ?? 'toHtml';
                unset($params['class'], $params['output']);

                return $blocks->render($class, $params, $method);
            });
        }

        if ($services->templateUrls !== null) {
            $templateUrls = $services->templateUrls;
            // `getUrl`, and only `getUrl`: StrictResolver names the method literally, and so
            // does this. Everything else keeps going through the getData() mapping.
            $evaluator->resolver()->serveMethodCalls(
                static function (object $target, string $method, array $args, Context $c) use ($templateUrls): ?Resolution {
                    if ($method !== 'getUrl') {
                        return null;
                    }

                    // StrictResolver overwrites the first argument with the scope's `store`
                    // before it calls anything, so a template cannot aim getUrl() at a store
                    // of its own choosing. The same overwrite, for the same reason.
                    $args[0] = $c->has('store') ? $c->get('store') : null;

                    $url = $templateUrls->urlFor($target, $args);

                    return $url === null ? null : Resolution::of($url);
                }
            );
        }

        if ($translator !== null) {
            // Body, modifiers, arguments and escaping are Evaluator::renderTrans()'s, exactly
            // as for the built-in {{trans}}. All this adds is the translation itself, which
            // is the only part a host does differently.
            $evaluator->register(
                'trans',
                static fn (DirectiveNode $n, Context $c, Evaluator $e): string => $e->renderTrans(
                    $n,
                    $c,
                    static fn (string $text, array $args): string => $translator->translate($text, $args)
                )
            );
        }

        if ($templates !== null) {
            // Inherit the evaluator's configuration - BOTH halves of it. A default Parser is
            // fully strict and would throw on an included template a lenient engine handles
            // fine; a default DirectiveSpec does not know the host's extra block types, so an
            // included template using one loses its body and leaks the closing tag as text.
            $parser ??= new Parser(spec: $evaluator->spec(), options: $evaluator->options());
            $evaluator->register('template', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($templates, $parser): string {
                // Legacy resolves any $-prefixed parameter against the scope before using
                // it, so `{{template config_path=$b}}` is an include of whatever `b` holds.
                $params = $e->params($n, $c);
                $path = $params['config_path'] ?? '';
                // No config_path at all is legacy's own error case, and it says so in the
                // output rather than rendering nothing.
                if ($path === '') {
                    return self::unresolvedInclude($e);
                }
                // A path that IS given but fails the guard renders nothing, like every other
                // guarded directive here. Guarded like {{config path=}} is: the shipped
                // ConfigTemplateLoader happens to allowlist, but the TemplateLoader port does
                // not require an implementation to, so the check belongs on this side of it.
                if (!PathGuard::isSafeConfigPath($path)) {
                    return '';
                }

                if ($c->includeBudgetExhausted()) {
                    throw TemplateCycleError::at(
                        '',
                        $n->offset(),
                        sprintf(
                            'Template includes exceeded the budget of %d loads for one render',
                            Options::DEFAULT_MAX_INCLUDES
                        ),
                        'include chain: ' . implode(' > ', [...$c->includeStack(), $path])
                    );
                }

                // Nothing found is not legacy's error case: with a processor set, legacy
                // returns whatever the processor returned, which for an unknown path is
                // empty. The error string is only for a missing config_path.
                $source = $templates->load($path);
                if ($source === null) {
                    return '';
                }

                if ($c->includeDepth() >= Options::DEFAULT_MAX_INCLUDE_DEPTH) {
                    throw TemplateCycleError::at(
                        '',
                        $n->offset(),
                        sprintf(
                            'Template includes nested more than %d deep',
                            Options::DEFAULT_MAX_INCLUDE_DEPTH
                        ),
                        'include chain: ' . implode(' > ', [...$c->includeStack(), $path])
                    );
                }

                if (!$c->enterInclude($path)) {
                    throw TemplateCycleError::at(
                        '',
                        $n->offset(),
                        sprintf('Template "%s" includes itself', $path),
                        'include chain: ' . implode(' > ', [...$c->includeStack(), $path])
                    );
                }

                try {
                    // Child scope. Its deferred work is handed back up explicitly - no
                    // shared state, and nothing survives in the output stream.
                    $child = $c->withVariables(self::includeScope($params, $c, $e));
                    $ast = $parser->parse($source, $c->policy()->maxNestingDepth());
                    $child->noteIncompatibilities($ast->incompatibilities());
                    $rendered = $e->evaluate($ast, $child);
                    $c->absorb($child);
                } finally {
                    $c->leaveInclude();
                }

                return $rendered;
            });
        }

        if ($services->config !== null) {
            $config = $services->config;
            $evaluator->register('config', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($config): string {
                $path = $e->params($n, $c)['path'] ?? '';
                if (!PathGuard::isSafeConfigPath($path)) {
                    return '';
                }
                return (string)($config->value($path) ?? '');
            });
        }

        if ($services->customVariables !== null) {
            $vars = $services->customVariables;
            $evaluator->register('customvar', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($vars): string {
                $code = $e->params($n, $c)['code'] ?? '';
                if (!PathGuard::isSafeVariableCode($code)) {
                    return '';
                }
                // The plain flag was hardcoded false, so a plain-text email got the variable's
                // HTML value - markup in a text/plain body. The port has taken this argument
                // since it was written; nothing was passing it.
                return (string)($vars->value($code, $c->plainText()) ?? '');
            });
        }

        if ($services->urls !== null) {
            $urls = $services->urls;

            $evaluator->register('store', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($urls): string {
                $params = $e->params($n, $c);

                // storeDirective keeps these apart, and so must this. `url` is a route path
                // that the URL model routes; `direct_url` becomes `_direct`, which
                // getRouteUrl() concatenates onto the base URL with no routing at all.
                // Collapsing them, as this did, made `{{store direct_url="customer/account"}}`
                // render a ROUTED url - `.../customer/account/` - where the filter emits the
                // base URL plus that text verbatim. Guarded either way; only the meaning
                // differed.
                $direct = $params['direct_url'] ?? null;
                $path = $direct !== null ? '' : ($params['url'] ?? '');
                unset($params['url'], $params['direct_url']);
                if ($direct !== null) {
                    $params['_direct'] = $direct;
                }
                if ($path !== '' && !PathGuard::isSafeRelativePath($path)) {
                    return '';
                }
                // Magento\Framework\Url::getRouteUrl() returns getBaseUrl() . $params['_direct']
                // with no filtering of its own, so a guard on `url=` alone is not a guard.
                // Any remaining parameter that names a path gets the same check.
                if (!self::pathParametersAreSafe($params)) {
                    return '';
                }
                return $urls->storeUrl($path, $params);
            });

            $evaluator->register('media', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($urls): string {
                $path = $e->params($n, $c)['url'] ?? '';
                // Legacy concatenates this straight onto the media base URL. An ABSENT path
                // concatenates nothing, so the directive is the media root - which is what
                // `{{media url=$p}}` with `$p` unset renders today, and refusing it instead
                // was a divergence on a shape a merchant reaches by typo'ing a variable name.
                if ($path === '') {
                    return $urls->mediaUrl('');
                }
                return PathGuard::isSafeRelativePath($path) ? $urls->mediaUrl($path) : '';
            });

            $evaluator->register('view', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($urls): string {
                $params = $e->params($n, $c);
                $path = $params['url'] ?? '';
                unset($params['url']);
                // As for {{media}}: an absent path is the static root, not a refusal.
                if ($path !== '' && !PathGuard::isSafeRelativePath($path)) {
                    return '';
                }
                if (!self::pathParametersAreSafe($params) || !self::designParametersAreSafe($params)) {
                    return '';
                }
                return $urls->viewUrl($path, $params);
            });

            $evaluator->register('protocol', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($urls): string {
                $params = $e->params($n, $c);
                $secure = $urls->isSecure();
                $scheme = $secure ? 'https' : 'http';

                // Legacy's order: url wins over the pair, and with neither the directive is
                // just the word 'http' or 'https' - which is the form stock templates use,
                // as `{{protocol}}://{{store url=''}}`. Returning '' for it, as this did,
                // silently unschemed every link in those templates.
                if (isset($params['url'])) {
                    $host = (string)$params['url'];
                    // Legacy is `$protocol . '://' . $params['url']` with no checking at all,
                    // so everything after the scheme is whatever the template said. Held to a
                    // host, an optional port and a path - the previous pattern had no port,
                    // which refused the `example.com:8080/a` legacy renders, and allowed every
                    // markup delimiter after the first slash.
                    if (!preg_match('#^[a-zA-Z0-9.-]+(?::[0-9]{1,5})?(/[^\s]*)?$#', $host, $m)) {
                        return '';
                    }
                    // Only the tail goes through the path guard. The host cannot: to a guard
                    // written for relative paths, `example.com:8080` IS a scheme, so checking
                    // the whole value refused every URL carrying a port.
                    $tail = ltrim($m[1] ?? '', '/');
                    if ($tail !== '' && !PathGuard::isSafeRelativePath($tail)) {
                        return '';
                    }
                    return $scheme . '://' . $host;
                }

                if (isset($params['http'], $params['https'])) {
                    // validateProtocolDirectiveHttpScheme requires each to parse and to carry
                    // its own scheme, and throws otherwise. Refused rather than thrown: a
                    // host-error shape, which this engine renders as a gap by design.
                    if (!PathGuard::isSafeAbsoluteUrl((string)$params['http'], 'http')
                        || !PathGuard::isSafeAbsoluteUrl((string)$params['https'], 'https')
                    ) {
                        return '';
                    }
                    return (string)($secure ? $params['https'] : $params['http']);
                }

                return $scheme;
            });
        }

        if ($services->stylesheets !== null) {
            $stylesheets = $services->stylesheets;
            $evaluator->register('css', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($stylesheets): string {
                // cssDirective opens with this: a stylesheet is not something a text/plain
                // body can carry, so plain mode renders nothing - not even the comment below.
                if ($c->plainText()) {
                    return '';
                }

                $file = $e->params($n, $c)['file'] ?? '';
                // cssDirective's own words for a missing file, so a template that has always
                // rendered this comment keeps rendering the same one. A file that is PRESENT
                // and refused gets this engine's own message instead: that is a refusal here,
                // not a message legacy has, and saying otherwise would misattribute it.
                if ($file === '') {
                    return '/* "file" parameter must be specified */';
                }
                if (!PathGuard::isSafeRelativePath($file)) {
                    return '/* invalid file parameter */';
                }
                return (string)($stylesheets->load($file) ?? '');
            });
        }

        if ($services->layouts !== null) {
            $layouts = $services->layouts;
            $evaluator->register('layout', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($layouts): string {
                $params = $e->params($n, $c);
                $handle = $params['handle'] ?? '';
                $area = $params['area'] ?? 'frontend';
                unset($params['handle'], $params['area']);

                if (!PathGuard::isSafeIdentifier($handle) || !in_array($area, ['frontend', 'adminhtml'], true)) {
                    return '';
                }
                return $layouts->render($handle, $area, $params);
            });
        }

        if ($services->widgets !== null) {
            $widgets = $services->widgets;
            $evaluator->register('widget', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($widgets): string {
                $params = $e->params($n, $c);
                $type = $params['type'] ?? '';
                unset($params['type']);

                if (!PathGuard::isSafeIdentifier($type)) {
                    return '';
                }
                if (!$c->policy()->permitsBlock($type)) {
                    $e->refusedByPolicy($n, $c, PolicyViolation::BLOCK, $type);
                    return '';
                }
                return $widgets->render($type, $params);
            });
        }
    }
}
