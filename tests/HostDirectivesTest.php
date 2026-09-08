<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use MageOS\TemplateParser\Context;
use MageOS\TemplateParser\Evaluator;
use MageOS\TemplateParser\HostDirectives;
use MageOS\TemplateParser\Parser;
use MageOS\TemplateParser\Port\BlockRenderer;
use MageOS\TemplateParser\Port\TemplateLoader;
use MageOS\TemplateParser\Port\Translator;
use MageOS\TemplateParser\TemplateEngine;
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
            new \MageOS\TemplateParser\HostServices($blocks, $translator, $templates),
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

        $out = $this->engine(blocks: $renderer)
            ->render('{{block class="Some\\Block" output="toHtml" title="Hi"}}');

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
        $this->engine(blocks: $renderer)->render('{{block class="X"}}');
        self::assertSame('toHtml', $renderer->method);
    }

    public function testTransResolvesArgumentsFromScope(): void
    {
        $translator = new class implements Translator {
            public function translate(string $text, array $arguments): string
            {
                foreach ($arguments as $k => $v) {
                    $text = str_replace('%' . $k, $v, $text);
                }
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
            ['payload' => '{{block class="Evil"}}']
        );

        self::assertSame(0, $renderer->calls, 'a directive from data must never execute');
        self::assertSame('child:{{block class="Evil"}}', $out);
    }
}
