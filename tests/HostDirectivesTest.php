<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\Context;
use Cresset\TemplateParser\Evaluator;
use Cresset\TemplateParser\HostDirectives;
use Cresset\TemplateParser\Parser;
use Cresset\TemplateParser\Port\BlockRenderer;
use Cresset\TemplateParser\Port\TemplateLoader;
use Cresset\TemplateParser\Port\Translator;
use Cresset\TemplateParser\TemplateEngine;
use Cresset\TemplateParser\HostServices;
use Cresset\TemplateParser\Options;
use Cresset\TemplateParser\RenderPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HostDirectivesTest extends TestCase
{
    private function engine(
        ?BlockRenderer $blocks = null,
        ?Translator $translator = null,
        ?TemplateLoader $templates = null
    ): TemplateEngine {
        $parser = new Parser();
        $evaluator = new Evaluator();
        HostDirectives::register(
            $evaluator,
            new \Cresset\TemplateParser\HostServices($blocks, $translator, $templates),
            $parser
        );
        return new TemplateEngine($parser, $evaluator);
    }

    public function testBlockDirectiveDelegatesToThePort(): void
    {
        $renderer = new class implements BlockRenderer {
            public array $calls = [];
            public function render(string $class, array $data, string $method): string
            {
                $this->calls[] = [$class, $data, $method];
                return '[rendered]';
            }
        };

        $out = $this->engine(blocks: $renderer)->render(
            '{{block class="Some\\Block" output="toHtml" title="Hi"}}',
            [],
            null,
            \Cresset\TemplateParser\RenderPolicy::unrestricted()
        );

        self::assertSame('[rendered]', $out);
        self::assertSame([['Some\\Block', ['title' => 'Hi'], 'toHtml']], $renderer->calls);
    }

    public function testBlockDefaultsToToHtmlWhenOutputIsAbsent(): void
    {
        $renderer = new class implements BlockRenderer {
            public string $method = '';
            public function render(string $class, array $data, string $method): string
            {
                $this->method = $method;
                return '';
            }
        };
        $this->engine(blocks: $renderer)->render(
            '{{block class="X"}}',
            [],
            null,
            \Cresset\TemplateParser\RenderPolicy::unrestricted()
        );
        self::assertSame('toHtml', $renderer->method);
    }

    public function testTransResolvesArgumentsFromScope(): void
    {
        $translator = new class implements Translator {
            public function translate(string $text, array $arguments): string
            {
                $text = strtr($text, $arguments);
                return '[' . $text . ']';
            }
        };

        $out = $this->engine(translator: $translator)
            ->render('{{trans "Hi %name" name=$who}}', ['who' => 'Jan']);

        self::assertSame('[Hi Jan]', $out);
    }

    public function testTemplateDirectiveRendersAChildAndAbsorbsItsDeferredWork(): void
    {
        $loader = new class implements TemplateLoader {
            public function load(string $configPath): ?string
            {
                return $configPath === 'design/email/header'
                    ? 'HEADER {{inlinecss file="child.css"}}'
                    : null;
            }
        };

        $context = new Context(['x' => 'v']);
        $out = $this->engine(templates: $loader)
            ->render('{{template config_path="design/email/header"}}|{{var x}}', [], $context);

        self::assertSame('HEADER |v', $out);
        // The child's deferred entry reached the parent scope without travelling as text.
        self::assertSame(
            [['kind' => 'inlinecss', 'payload' => ['file' => 'child.css']]],
            $context->deferred()
        );
    }

    public function testUnknownTemplatePathRendersNothing(): void
    {
        $loader = new class implements TemplateLoader {
            public function load(string $configPath): ?string { return null; }
        };
        self::assertSame('', $this->engine(templates: $loader)->render('{{template config_path="nope"}}'));
    }

    /** A child template's directives are parsed from its own source, never from data. */
    public function testChildTemplateCannotBeInjectedThroughAVariable(): void
    {
        $renderer = new class implements BlockRenderer {
            public int $calls = 0;
            public function render(string $class, array $data, string $method): string
            {
                $this->calls++;
                return '[X]';
            }
        };
        $loader = new class implements TemplateLoader {
            public function load(string $configPath): ?string { return 'child:{{var payload|raw}}'; }
        };

        $out = $this->engine(blocks: $renderer, templates: $loader)->render(
            '{{template config_path="design/email/header"}}',
            ['payload' => '{{block class="Evil"}}'],
            null,
            \Cresset\TemplateParser\RenderPolicy::unrestricted()
        );

        self::assertSame(0, $renderer->calls, 'a directive from data must never execute');
        self::assertSame('child:{{block class="Evil"}}', $out);
    }

    /**
     * {{template}} matches legacy's own handling of an include it cannot process.
     *
     * TemplateDirective::process returns the literal string "{Error in template processing}"
     * when config_path is absent - not an exception, not empty output, but text that ends up
     * in the email. This engine rendered nothing, which is a divergence on every template
     * with a malformed include. Legacy also resolves $-prefixed parameters before using
     * them, so `config_path=$b` is an include of whatever `b` holds.
     */
    #[DataProvider('includeShapes')]
    public function testIncludesMatchLegacyHandling(string $template, string $compatible, string $lenient): void
    {
        $loader = new class implements TemplateLoader {
            public function load(string $path): ?string
            {
                return $path === 'design/email/header' ? 'HEADER' : null;
            }
        };

        foreach (['compatible' => $compatible, 'lenient' => $lenient] as $mode => $expected) {
            $options = $mode === 'compatible' ? Options::compatible() : Options::lenient();
            $evaluator = new Evaluator(options: $options);
            HostDirectives::register($evaluator, new HostServices(templates: $loader));
            $engine = new TemplateEngine(new Parser(options: $options), $evaluator);

            self::assertSame(
                $expected,
                $engine->render($template, context: new Context(['b' => 'design/email/header'], RenderPolicy::unrestricted())),
                $mode . ': ' . $template
            );
        }
    }

    public static function includeShapes(): array
    {
        // The compatible column is what the real filter produces, verified against it - the
        // braces arrive encoded because the StyleSmuggler hardening neutralises them.
        return [
            'resolvable'          => ['{{template config_path="design/email/header"}}', 'HEADER', 'HEADER'],
            'unknown path'        => ['{{template config_path="design/email/nope"}}', '', ''],
            'missing config_path' => ['{{template}}', '&#123;Error in template processing}', ''],
            'other param only'    => ['{{template foo="1"}}', '&#123;Error in template processing}', ''],
            'dollar prefixed'     => ['{{template config_path=$b}}', 'HEADER', 'HEADER'],
        ];
    }

    /** A path the guard refuses renders nothing, like every other guarded directive. */
    public function testAnUnsafeIncludePathRendersNothingAndIsNotLoaded(): void
    {
        $seen = [];
        $loader = new class ($seen) implements TemplateLoader {
            public function __construct(private array &$seen) {}
            public function load(string $path): ?string { $this->seen[] = $path; return 'LOADED'; }
        };

        $evaluator = new Evaluator(options: Options::compatible());
        HostDirectives::register($evaluator, new HostServices(templates: $loader));
        $engine = new TemplateEngine(new Parser(options: Options::compatible()), $evaluator);

        $out = $engine->render(
            '{{template config_path="../../app/etc/env.php"}}',
            context: new Context([], RenderPolicy::unrestricted())
        );

        self::assertSame('', $out);
        self::assertSame([], $seen, 'the refused path reached the loader');
    }
}
