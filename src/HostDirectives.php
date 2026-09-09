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
    private static function pathParametersAreSafe(array $parameters): bool
    {
        foreach (['_direct', '_fragment', '_escape_params'] as $key) {
            $value = $parameters[$key] ?? null;
            if (is_string($value) && $value !== '' && !PathGuard::isSafeRelativePath($value)) {
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
                if (!PathGuard::isSafeIdentifier($code)) {
                    return '';
                }
                return (string)($vars->value($code, false) ?? '');
            });
        }

        if ($services->urls !== null) {
            $urls = $services->urls;

            $evaluator->register('store', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($urls): string {
                $params = $e->params($n, $c);
                $path = $params['url'] ?? ($params['direct_url'] ?? '');
                unset($params['url'], $params['direct_url']);
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
                // Legacy concatenates this straight onto the media base URL.
                return PathGuard::isSafeRelativePath($path) ? $urls->mediaUrl($path) : '';
            });

            $evaluator->register('view', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($urls): string {
                $params = $e->params($n, $c);
                $path = $params['url'] ?? '';
                unset($params['url']);
                if (!PathGuard::isSafeRelativePath($path) || !self::pathParametersAreSafe($params)) {
                    return '';
                }
                return $urls->viewUrl($path, $params);
            });

            $evaluator->register('protocol', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($urls): string {
                $params = $e->params($n, $c);
                $scheme = $urls->isSecure() ? 'https' : 'http';

                if (isset($params['http'], $params['https'])) {
                    $chosen = $urls->isSecure() ? $params['https'] : $params['http'];
                    return PathGuard::isSafeRelativePath($chosen) ? $chosen : '';
                }

                $host = $params['url'] ?? '';
                // Legacy does `$protocol . '://' . $params['url']` with no checking at all.
                if ($host === '' || !preg_match('#^[a-zA-Z0-9.-]+(/[^\s]*)?$#', $host)) {
                    return '';
                }
                return $scheme . '://' . $host;
            });
        }

        if ($services->stylesheets !== null) {
            $stylesheets = $services->stylesheets;
            $evaluator->register('css', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($stylesheets): string {
                $file = $e->params($n, $c)['file'] ?? '';
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
