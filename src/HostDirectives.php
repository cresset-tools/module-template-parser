<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

use Cresset\TemplateParser\Ast\DirectiveNode;
use Cresset\TemplateParser\Port\RefusedByPort;

/**
 * Registers the directives that need something from the host application.
 *
 * Everything here is opt-in: a directive with no port supplied stays unregistered, which
 * means it is reported in strict mode and rendered verbatim in lenient mode. Nothing is
 * ever dispatched by reflection.
 *
 * Values reach the guards as written; PathGuard does the decoding, so the ORIGINAL value is
 * what reaches the port - `a&amp;b` arrives as `a&b`, which is what legacy emits.
 */
final class HostDirectives
{
    /**
     * Splits a custom directive's text the way `SimpleDirective` splits it.
     *
     * Its pattern is `{{name "value" parameters|filters}}`, where every part after the name is
     * optional - so the three are pulled off in that order and whatever is left is parameters.
     * The quote handling is the pattern's, down to the shape of the group: `(?:(?!\1).)*?`
     * cannot consume the quote it is looking for, so nothing escapes one - `{{mydir "a\"b"}}`
     * fails the match outright and the whole text stays parameters. The lookbehind's only
     * effect is refusing a value that ends in a backslash.
     *
     * @return array{0:?string,1:string,2:string[]} value, parameter text, modifiers
     */
    private static function splitCustomDirective(string $params): array
    {
        $text = ltrim($params);

        $value = null;
        if (preg_match('/^([\'"])((?:(?!\1).)*?)(?<!\\\\)\1/s', $text, $m) === 1) {
            $value = $m[2];
            $text = substr($text, strlen($m[0]));
        }

        $modifiers = [];
        // `\s*$` and `\s*\z` read the same: the `\s*` has already taken any trailing
        // newline. This splits, it does not guard - the guards use `\z`, see PathGuard.
        if (preg_match('/((?:\|[a-z0-9:_-]+)+)\s*$/i', $text, $m) === 1) {
            $modifiers = array_values(array_filter(explode('|', ltrim($m[1], '|'))));
            $text = substr($text, 0, -strlen($m[1]));
        }

        return [$value, $text, $modifiers];
    }

    /**
     * The shapes the design parameters must take, since each becomes a static-URL path segment.
     *
     * @var array<string,string>
     */
    private const DESIGN_PARAMETER_SHAPES = [
        'area' => '/^[a-zA-Z0-9_]{1,64}\z/',
        'locale' => '/^[a-zA-Z0-9_-]{1,32}\z/',
        'module' => '/^[a-zA-Z0-9_]{1,128}\z/',
        'theme' => '#^[a-zA-Z0-9_]{1,64}/[a-zA-Z0-9_-]{1,64}\z#',
        'themeId' => '/^[0-9]{1,10}\z/',
    ];

    /**
     * Whether every design parameter is a shape Asset\Repository can make a path segment of.
     *
     * FallbackContext::generatePath() is `$area . '/' . $theme . '/' . $locale` with no
     * validation of any of the three, and `module` becomes a segment of its own, so
     * `{{view url="x" locale="../../.."}}` climbs out of the static root while still reading
     * as a same-origin URL. Each is held to its own shape rather than to a path guard,
     * because none of them is a path: an area and a module are identifiers, a locale is
     * `en_US`, and a theme is exactly `Vendor/name`.
     *
     * @param array<string,mixed> $parameters
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

    /**
     * Renders nothing, and records why, where legacy would have rendered something.
     *
     * Every guard in this class that is stricter than the filter it stands in for ends here
     * rather than in a bare `return ''`. A bare '' is invisible: the render looks complete,
     * Shadow can only report it as a byte difference, and Parser mode SERVES it - which is how
     * a real store's order emails went out without their item table. Recorded as a policy
     * violation, it is declined instead: Shadow reports it as a refusal with the reason, and
     * Parser hands the render to the legacy filter.
     */
    private static function declined(DirectiveNode $n, Context $c, Evaluator $e, string $kind, string $name): string
    {
        $e->refusedByPolicy($n, $c, $kind, $name);

        return '';
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
                    // `{{block id="footer"}}` is a CMS block by identifier: blockDirective
                    // builds Magento\Cms\Block\Block for it. This engine has no such path,
                    // so it says so. Neither class nor id is legacy's own '' too.
                    return isset($params['id']) && $params['id'] !== ''
                        ? self::declined($n, $c, $e, 'block id', (string)$params['id'])
                        : '';
                }

                // Checked before the port, so a refused class is never constructed.
                if (!$c->policy()->permitsBlock($class)) {
                    $e->refusedByPolicy($n, $c, PolicyViolation::BLOCK, $class);
                    return '';
                }

                $method = $params['output'] ?? 'toHtml';
                unset($params['class'], $params['output']);

                try {
                    return $blocks->render($class, $params, $method);
                } catch (RefusedByPort $refused) {
                    return self::declined($n, $c, $e, $refused->kind, $refused->name);
                }
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

                    try {
                        $url = $templateUrls->urlFor($target, $args);
                    } catch (RefusedByPort $refused) {
                        // No directive node to point at: this is a method call inside a
                        // {{var}}. Recorded all the same, so the render is declined.
                        $c->recordViolation(new PolicyViolation($refused->kind, $refused->name, 0, 0));
                        $url = '';
                    }

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
                    // The filter loads whatever path it is given.
                    return self::declined($n, $c, $e, 'template config path', $path);
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
                try {
                    $source = $templates->load($path);
                } catch (RefusedByPort $refused) {
                    return self::declined($n, $c, $e, $refused->kind, $refused->name);
                }
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
                // A path the guard refuses cannot be on Magento's list of config variables,
                // which is all configDirective renders - so legacy renders '' for it too.
                if (!PathGuard::isSafeConfigPath($path)) {
                    return '';
                }
                try {
                    return (string)($config->value($path) ?? '');
                } catch (RefusedByPort $refused) {
                    return self::declined($n, $c, $e, $refused->kind, $refused->name);
                }
            });
        }

        if ($services->customVariables !== null) {
            $vars = $services->customVariables;
            $evaluator->register('customvar', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($vars): string {
                $code = $e->params($n, $c)['code'] ?? '';
                if ($code === '') {
                    return '';
                }
                // The filter looks up any code it is given.
                if (!PathGuard::isSafeVariableCode($code)) {
                    return self::declined($n, $c, $e, 'custom variable code', $code);
                }
                // The plain flag decides which stored value is read. Passing false
                // unconditionally puts the variable's HTML into a text/plain body.
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
                // Collapsing them makes `{{store direct_url="customer/account"}}` render a
                // ROUTED url - `.../customer/account/` - where the filter emits the base URL
                // plus that text verbatim. Guarded either way; only the meaning differs.
                $direct = $params['direct_url'] ?? null;
                $path = $direct !== null ? '' : ($params['url'] ?? '');
                unset($params['url'], $params['direct_url']);
                if ($direct !== null) {
                    $params['_direct'] = $direct;
                }
                // The filter builds a URL from whatever it is given; each refusal here is this
                // engine's, so each is declined rather than rendered as nothing.
                if ($path !== '' && !PathGuard::isSafeRelativePath($path)) {
                    return self::declined($n, $c, $e, 'store url', $path);
                }
                // Magento\Framework\Url::getRouteUrl() returns getBaseUrl() . $params['_direct']
                // with no filtering of its own, so a guard on `url=` alone is not a guard.
                // Any remaining parameter that names a path gets the same check.
                if (!PathGuard::routeParametersAreSafe($params)) {
                    return self::declined($n, $c, $e, 'store url parameters', (string)($direct ?? $path));
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
                // The filter concatenates any path onto the media base URL.
                return PathGuard::isSafeRelativePath($path)
                    ? $urls->mediaUrl($path)
                    : self::declined($n, $c, $e, 'media url', $path);
            });

            $evaluator->register('view', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($urls): string {
                $params = $e->params($n, $c);
                $path = $params['url'] ?? '';
                unset($params['url']);
                // As for {{media}}: an absent path is the static root, not a refusal.
                // The filter hands any of these to the asset repository.
                if ($path !== '' && !PathGuard::isSafeRelativePath($path)) {
                    return self::declined($n, $c, $e, 'view url', $path);
                }
                if (!PathGuard::routeParametersAreSafe($params) || !self::designParametersAreSafe($params)) {
                    return self::declined($n, $c, $e, 'view url parameters', $path);
                }
                return $urls->viewUrl($path, $params);
            });

            $evaluator->register('protocol', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($urls): string {
                $params = $e->params($n, $c);
                $secure = $urls->isSecure();
                $scheme = $secure ? 'https' : 'http';

                // Legacy's order: url wins over the pair, and with neither the directive is
                // just the word 'http' or 'https' - which is the form stock templates use,
                // as `{{protocol}}://{{store url=''}}`. Returning '' for it silently
                // unschemes every link in those templates.
                if (isset($params['url'])) {
                    $host = (string)$params['url'];
                    // Legacy is `$protocol . '://' . $params['url']` with no checking at all,
                    // so everything after the scheme is whatever the template said. Held to a
                    // host, an optional port and a path - the previous pattern had no port,
                    // which refused the `example.com:8080/a` legacy renders, and allowed every
                    // markup delimiter after the first slash.
                    if (!preg_match('#^[a-zA-Z0-9.-]+(?::[0-9]{1,5})?(/[^\s]*)?\z#', $host, $m)) {
                        return self::declined($n, $c, $e, 'protocol url', $host);
                    }
                    // Only the tail goes through the path guard. The host cannot: to a guard
                    // written for relative paths, `example.com:8080` IS a scheme, so checking
                    // the whole value refused every URL carrying a port.
                    $tail = ltrim($m[1] ?? '', '/');
                    if ($tail !== '' && !PathGuard::isSafeRelativePath($tail)) {
                        return self::declined($n, $c, $e, 'protocol url', $host);
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
                        // Legacy throws a MailException for an invalid one, which its catch
                        // turns into an error page - not this engine's '' either.
                        return self::declined($n, $c, $e, 'protocol url', (string)$params['http'] . ' ' . (string)$params['https']);
                    }
                    return (string)($secure ? $params['https'] : $params['http']);
                }

                return $scheme;
            });
        }

        if ($services->customDirectives !== null) {
            $custom = $services->customDirectives;
            foreach ($custom->names() as $name) {
                $evaluator->register(
                    $name,
                    static function (DirectiveNode $n, Context $c, Evaluator $e) use ($custom, $name): string {
                        [$value, $parameterText, $modifiers] = self::splitCustomDirective($n->params());

                        // `$name` values resolve, exactly as extractParameters() resolves them,
                        // and nothing else about a parameter is interpreted.
                        $parameters = $e->params(
                            new DirectiveNode($n->name(), $parameterText, $n->fullRaw(), $n->offset()),
                            $c
                        );

                        // The body reaches the host RENDERED. The filter renders it too -
                        // `$filter->filter($construction['content'])` - so a processor sees a
                        // resolved variable rather than the directive text that produced it.
                        // Null, not '', when the directive had no body at all: the two are
                        // different arguments to a processor and the filter keeps them apart.
                        $body = $n->children() === [] ? null : $e->renderNodes($n->children(), $c);

                        try {
                            return (string)($custom->render($name, $value, $parameters, $body, $modifiers) ?? '');
                        } catch (RefusedByPort $refused) {
                            return self::declined($n, $c, $e, $refused->kind, $refused->name);
                        }
                    }
                );
            }
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
                    // The filter hands any file to the asset repository.
                    self::declined($n, $c, $e, 'stylesheet', $file);

                    return '/* invalid file parameter */';
                }
                try {
                    return (string)($stylesheets->load($file, $c->designParams()) ?? '');
                } catch (RefusedByPort $refused) {
                    return self::declined($n, $c, $e, $refused->kind, $refused->name);
                }
            });
        }

        if ($services->layouts !== null) {
            $layouts = $services->layouts;
            $evaluator->register('layout', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($layouts): string {
                $params = $e->params($n, $c);
                $handle = (string)($params['handle'] ?? '');
                // layoutDirective's reading of the area: trimmed, empty meaning frontend.
                $area = trim((string)($params['area'] ?? ''));
                $area = $area !== '' ? $area : 'frontend';
                unset($params['handle'], $params['area']);

                // Mage-OS 3.5.0 refuses an adminhtml handle outright, case-insensitively, and
                // renders nothing for it. So does this - silently, because that IS legacy.
                if (strcasecmp($area, 'adminhtml') === 0) {
                    return '';
                }
                // Any other area the filter emulates, and any handle it loads.
                if ($area !== 'frontend') {
                    return self::declined($n, $c, $e, 'layout area', $area);
                }
                if (!PathGuard::isSafeIdentifier($handle)) {
                    return self::declined($n, $c, $e, PolicyViolation::LAYOUT_HANDLE, $handle);
                }
                try {
                    return $layouts->render($handle, $area, $params);
                } catch (RefusedByPort $refused) {
                    return self::declined($n, $c, $e, $refused->kind, $refused->name);
                }
            });
        }

        if ($services->widgets !== null) {
            $widgets = $services->widgets;
            $evaluator->register('widget', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($widgets): string {
                $params = $e->params($n, $c);
                $type = $params['type'] ?? '';
                unset($params['type']);

                // `{{widget id="3"}}` is a preconfigured widget instance, which generateWidget
                // loads from the database. This engine has no such path.
                if ($type === '' && isset($params['id']) && $params['id'] !== '') {
                    return self::declined($n, $c, $e, 'widget id', (string)$params['id']);
                }
                // A type that is not an identifier is not in any widget.xml either, so
                // generateWidget renders nothing for it too.
                if (!PathGuard::isSafeIdentifier($type)) {
                    return '';
                }
                if (!$c->policy()->permitsBlock($type)) {
                    $e->refusedByPolicy($n, $c, PolicyViolation::BLOCK, $type);
                    return '';
                }
                try {
                    return $widgets->render($type, $params);
                } catch (RefusedByPort $refused) {
                    return self::declined($n, $c, $e, $refused->kind, $refused->name);
                }
            });
        }
    }
}
