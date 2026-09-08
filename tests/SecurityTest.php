<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use MageOS\TemplateParser\Ast\DirectiveNode;
use MageOS\TemplateParser\Context;
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

    /** Depth does not change the rule - there is no depth-sensitive behaviour at all. */
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

    /** Method calls are limited to argument-less accessors. */
    public function testOnlyAccessorMethodsAreCallable(): void
    {
        $subject = new class {
            public bool $wiped = false;
            public function getName(): string { return 'ok'; }
            public function deleteEverything(): string { $this->wiped = true; return 'boom'; }
        };
        self::assertSame('ok', $this->engine->render('{{var o.getName()}}', ['o' => $subject]));

        // A non-accessor does not resolve; strict mode reports it rather than calling it.
        try {
            $this->engine->render('{{var o.deleteEverything()}}', ['o' => $subject]);
            self::fail('expected the call to be refused');
        } catch (\MageOS\TemplateParser\UnknownVariableError $e) {
            self::assertStringContainsString('deleteEverything()', $e->getMessage());
        }
        self::assertFalse($subject->wiped, 'the method must never have been invoked');
    }
}
