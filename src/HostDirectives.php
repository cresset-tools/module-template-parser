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
     * Decodes HTML entities before guarding a path.
     *
     * Handler output is inserted unescaped, so a browser decodes `&#46;&#46;/x` back to
     * `../x` - the guard has to see the same string the browser will. ENT_HTML5 matters as
     * well as ENT_QUOTES: the default HTML 4.01 table has no `&period;`, so `&period;&period;/x`
     * would survive a decode that only asked for ENT_QUOTES.
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
            if (is_string($value) && $value !== '' && !PathGuard::isSafeRelativePath(self::decodeEntities($value))) {
                return false;
            }
        }

        return true;
    }

    private static function decodeEntities(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
                $params = $e->params($n);
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

        if ($translator !== null) {
            $evaluator->register('trans', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($translator): string {
                [$text, $args] = $e->splitTransParams($n->params());
                $resolved = [];
                foreach ($args as $key => $expression) {
                    $value = $e->resolver()->value(ltrim($expression, '$'), $c);
                    // stringify(), not a bare cast - an object with no __toString is a
                    // perfectly ordinary template variable and must not be a fatal.
                    // Escaped, for the same reason the built-in {{trans}} escapes: these are
                    // variable values going into output the directive does not escape later.
                    $resolved[$key] = $value === null ? $expression : $e->escapeValue($value);
                }
                return $translator->translate($text, $resolved);
            });
        }

        if ($templates !== null) {
            // Inherit the evaluator's configuration - BOTH halves of it. A default Parser is
            // fully strict and would throw on an included template a lenient engine handles
            // fine; a default DirectiveSpec does not know the host's extra block types, so an
            // included template using one loses its body and leaks the closing tag as text.
            $parser ??= new Parser(spec: $evaluator->spec(), options: $evaluator->options());
            $evaluator->register('template', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($templates, $parser): string {
                $params = $e->params($n);
                $path = $params['config_path'] ?? '';
                // Guarded like {{config path=}} is. The shipped ConfigTemplateLoader happens
                // to allowlist, but the TemplateLoader port does not require an implementation
                // to, so the check belongs on this side of it.
                if ($path === '' || !PathGuard::isSafeConfigPath($path)) {
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
                    $child = $c->withVariables([]);
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
                $path = $e->params($n)['path'] ?? '';
                if (!PathGuard::isSafeConfigPath($path)) {
                    return '';
                }
                return (string)($config->value($path) ?? '');
            });
        }

        if ($services->customVariables !== null) {
            $vars = $services->customVariables;
            $evaluator->register('customvar', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($vars): string {
                $code = $e->params($n)['code'] ?? '';
                if (!PathGuard::isSafeIdentifier($code)) {
                    return '';
                }
                return (string)($vars->value($code, false) ?? '');
            });
        }

        if ($services->urls !== null) {
            $urls = $services->urls;

            $evaluator->register('store', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($urls): string {
                $params = $e->params($n);
                $path = self::decodeEntities($params['url'] ?? ($params['direct_url'] ?? ''));
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
                $path = self::decodeEntities($e->params($n)['url'] ?? '');
                // Legacy concatenates this straight onto the media base URL.
                return PathGuard::isSafeRelativePath($path) ? $urls->mediaUrl($path) : '';
            });

            $evaluator->register('view', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($urls): string {
                $params = $e->params($n);
                $path = self::decodeEntities($params['url'] ?? '');
                unset($params['url']);
                if (!PathGuard::isSafeRelativePath($path) || !self::pathParametersAreSafe($params)) {
                    return '';
                }
                return $urls->viewUrl($path, $params);
            });

            $evaluator->register('protocol', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($urls): string {
                $params = $e->params($n);
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
                $file = $e->params($n)['file'] ?? '';
                if (!PathGuard::isSafeRelativePath($file)) {
                    return '/* invalid file parameter */';
                }
                return (string)($stylesheets->load($file) ?? '');
            });
        }

        if ($services->layouts !== null) {
            $layouts = $services->layouts;
            $evaluator->register('layout', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($layouts): string {
                $params = $e->params($n);
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
                $params = $e->params($n);
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
