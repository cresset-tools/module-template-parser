<?php
declare(strict_types=1);

namespace MageOS\TemplateParser;

use MageOS\TemplateParser\Ast\DirectiveNode;
use MageOS\TemplateParser\Ast\DirectiveNode as Node;

/**
 * Registers the directives that need something from the host application.
 *
 * Everything here is opt-in: a directive with no port supplied stays unregistered, which
 * means it is reported in strict mode and rendered verbatim in lenient mode. Nothing is
 * ever dispatched by reflection.
 */
final class HostDirectives
{
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
                    $resolved[$key] = $value === null ? $expression : (string)$value;
                }
                return $translator->translate($text, $resolved);
            });
        }

        if ($templates !== null) {
            $parser ??= new Parser();
            $evaluator->register('template', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($templates, $parser): string {
                $params = $e->params($n);
                $path = $params['config_path'] ?? '';
                $source = $path === '' ? null : $templates->load($path);
                if ($source === null) {
                    return '';
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
                    $rendered = $e->evaluate($parser->parse($source), $child);
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
                $path = $params['url'] ?? ($params['direct_url'] ?? '');
                unset($params['url'], $params['direct_url']);
                if ($path !== '' && !PathGuard::isSafeRelativePath($path)) {
                    return '';
                }
                return $urls->storeUrl($path, $params);
            });

            $evaluator->register('media', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($urls): string {
                $path = html_entity_decode($e->params($n)['url'] ?? '', ENT_QUOTES);
                // Legacy concatenates this straight onto the media base URL.
                return PathGuard::isSafeRelativePath($path) ? $urls->mediaUrl($path) : '';
            });

            $evaluator->register('view', static function (DirectiveNode $n, Context $c, Evaluator $e) use ($urls): string {
                $params = $e->params($n);
                $path = $params['url'] ?? '';
                unset($params['url']);
                return PathGuard::isSafeRelativePath($path) ? $urls->viewUrl($path, $params) : '';
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
                return $widgets->render($type, $params);
            });
        }
    }
}
