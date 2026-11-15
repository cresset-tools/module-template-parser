<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use MageOS\TemplateParser\Ast\DirectiveNode;
use MageOS\TemplateParser\Context;
use MageOS\TemplateParser\Options;
use MageOS\TemplateParser\TemplateEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The properties this engine exists to guarantee.
 */
final class SecurityTest extends TestCase
{
    private TemplateEngine $engine;
    /** @var string[] */
    private array $instantiated = [];

    protected function setUp(): void
    {
        $this->engine = new TemplateEngine();
        $this->instantiated = [];
        // Canary: records instantiation without constructing anything.
        $this->engine->evaluator()->register('block', function (DirectiveNode $n): string {
            $params = $this->engine->evaluator()->params($n);
            $this->instantiated[] = $params['class'] ?? '(none)';
            return '[BLOCK]';
        });
    }

    /** The whole point: a value is a value, never source. */
    #[DataProvider('injectionPayloads')]
    public function testDirectivesInsideVariableValuesAreNeverExecuted(string $payload): void
    {
        $out = $this->engine->render('Billing: {{var addr|raw}}', ['addr' => $payload]);

        self::assertSame([], $this->instantiated, 'a directive from data was executed');
        self::assertStringContainsString('{{block', $out, 'payload should survive as literal text');
    }

    public static function injectionPayloads(): array
    {
        $block = '{{block class=Magento\Email\Block\Adminhtml\Template\Preview}}';
        $mirror = '{{if postcode}}{{var postcode}}{{/if}}';
        return [
            'bare block'          => [$block],
            'block in if'         => ['{{if city}}' . $block . '{{/if}}'],
            'StyleSmuggler shape' => [$mirror . '{{/var}}' . $mirror . '{{if city}}' . $block . '{{/if}}'],
            'stray close tag'     => ['{{/var}}' . $block],
            'nested doubling'     => ['{{var x}}{{var x}}' . $block],
        ];
    }

    /** A self-referential variable must not cause re-entry or looping. */
    public function testSelfReferentialVariableIsInert(): void
    {
        $out = $this->engine->render('[{{var postcode|raw}}]', ['postcode' => '{{var postcode}}']);
        self::assertSame('[{{var postcode}}]', $out);
    }

    /** Depth does not change the rule; the only depth-sensitive thing is the bound itself. */
    public function testNestingDepthDoesNotEnableExecution(): void
    {
        $payload = '{{block class=X}}';
        $out = $this->engine->render(
            '{{if a}}{{if b}}{{if c}}{{var p|raw}}{{/if}}{{/if}}{{/if}}',
            ['a' => 1, 'b' => 1, 'c' => 1, 'p' => $payload]
        );
        self::assertSame([], $this->instantiated);
        self::assertSame($payload, $out);
    }

    /** Directives written in the template itself still work - the harness is not over-defending. */
    public function testDirectiveWrittenInTheTemplateIsExecuted(): void
    {
        // {{block}} is denied by the default policy, so grant it - otherwise this would
        // pass for the wrong reason and the payload tests above would prove nothing.
        $out = $this->engine->render(
            '{{block class=Some\Real\Block}}',
            [],
            null,
            \MageOS\TemplateParser\RenderPolicy::unrestricted()
        );
        self::assertSame(['Some\Real\Block'], $this->instantiated);
        self::assertSame('[BLOCK]', $out);
    }

    /** Deferral is structured, so nothing needs to survive in the output stream. */
    public function testDeferredWorkIsStructuredNotEmittedAsText(): void
    {
        $context = new Context([]);
        $out = $this->engine->render('a{{inlinecss file="css/email.css"}}b', [], $context);

        self::assertSame('ab', $out, 'deferred directive must not appear in output');
        self::assertSame(
            [['kind' => 'inlinecss', 'payload' => ['file' => 'css/email.css']]],
            $context->deferred()
        );
    }

    /** A child render hands its deferred work up one level; no shared or request state. */
    public function testChildDeferralIsAbsorbedByTheParentScope(): void
    {
        $parent = new Context([]);
        $child = new Context([]);
        $this->engine->render('{{inlinecss file="child.css"}}', [], $child);
        $parent->absorb($child);

        self::assertSame([['kind' => 'inlinecss', 'payload' => ['file' => 'child.css']]], $parent->deferred());
    }

    /** Values are escaped by default; only an explicit modifier opts out. */
    public function testVariablesAreEscapedByDefault(): void
    {
        self::assertSame('&lt;b&gt;', $this->engine->render('{{var x}}', ['x' => '<b>']));
        self::assertSame('<b>', $this->engine->render('{{var x|raw}}', ['x' => '<b>']));
    }

    /**
     * No method is ever called, in any posture: `o.getName()` is a spelling of getData('name'),
     * as in Magento's StrictResolver. Strict and lenient used to call any public get/has/is
     * method - and chain through what it returned - so a template could walk from the email
     * model to whatever its accessors reach, running each on the way.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('postures')]
    public function testNoMethodIsEverCalled(Options $options): void
    {
        $engine = TemplateEngine::withOptions($options);
        $subject = new class {
            public int $calls = 0;
            public string $secret = 'S3CRET';
            public function getName(): string { $this->calls++; return 'ran'; }
            public function getResource(): object { $this->calls++; return $this; }
            public function isAdmin(): bool { $this->calls++; return true; }
        };

        foreach (['{{var o.getName()}}', '{{var o.name}}', '{{var o.resource.secret}}', '{{var o.secret}}', '{{if o.isAdmin()}}yes{{/if}}'] as $template) {
            try {
                self::assertSame('', $engine->render($template, ['o' => $subject]), $template);
            } catch (\MageOS\TemplateParser\UnknownVariableError) {
                // strict says so out loud; either way nothing ran
            }
        }
        self::assertSame(0, $subject->calls, 'a method on a template variable was invoked');
    }

    /** @return array<string,array{Options}> */
    public static function postures(): array
    {
        return ['strict' => [Options::strict()], 'lenient' => [Options::lenient()], 'compatible' => [Options::compatible()]];
    }

    /** Through the data bag, a getter-shaped call reads the mapped key - and only that. */
    public function testAGetterCallReadsTheDataBag(): void
    {
        $bag = new class {
            public function getData($key = '') { return ['first_name' => 'Ada'][$key] ?? null; }
            public function hasData($key = '') { return $key === 'first_name'; }
            public function getFirstName() { throw new \LogicException('the real method must not run'); }
        };

        self::assertSame('Ada', $this->engine->render('{{var c.getFirstName()}}', ['c' => $bag]));
        self::assertSame('Ada', $this->engine->render('{{var c.first_name}}', ['c' => $bag]));
    }

    /**
     * Compatible mode reads the bag unconditionally, as StrictResolver does: a model computing
     * a value in an overridden getData() holds nothing hasData() can see.
     */
    public function testCompatibleModeReadsAComputedDataValue(): void
    {
        $model = new class {
            public function getData($key = '') { return $key === 'total_due' ? '12.50' : null; }
            public function hasData($key = '') { return false; }
        };

        self::assertSame('12.50', TemplateEngine::compatible()->render('{{var o.total_due}}', ['o' => $model]));
    }
}
