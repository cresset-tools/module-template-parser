<?php
declare(strict_types=1);

namespace MageOS\TemplateParser;

use MageOS\TemplateParser\Ast\DirectiveNode;
use MageOS\TemplateParser\Port\BlockRenderer;
use MageOS\TemplateParser\Port\TemplateLoader;
use MageOS\TemplateParser\Port\Translator;

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
        ?BlockRenderer $blocks = null,
        ?Translator $translator = null,
        ?TemplateLoader $templates = null,
        ?Parser $parser = null
    ): void {
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
    }
}
