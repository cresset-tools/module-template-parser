<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test\HostDeferralFixtures {
    class Registry
    {
    }

    /** Shaped like Mage-OS 3.6.0's filter: the registry is a constructor argument. */
    class RegistryFilter
    {
        public function __construct(
            object $string,
            array $variables = [],
            ?\MageOS\TemplateParser\Test\HostDeferralFixtures\Registry $deferredDirectiveRegistry = null
        ) {
        }
    }

    /** Shaped like 3.5.0's: signature-based deferral, no registry argument. */
    class SignatureFilter
    {
        public function __construct(object $string, array $variables = [])
        {
        }
    }
}

namespace MageOS\TemplateParser\Test {

    use MageOS\TemplateParser\Context;
    use MageOS\TemplateParser\HostServices;
    use MageOS\TemplateParser\Magento\HostDeferral;
    use MageOS\TemplateParser\Magento\ShadowComparator;
    use MageOS\TemplateParser\Magento\Shadow\ShadowOutcome;
    use MageOS\TemplateParser\Magento\TemplateFilterAdapter;
    use MageOS\TemplateParser\Options;
    use MageOS\TemplateParser\Port\TemplateLoader;
    use MageOS\TemplateParser\TemplateEngine;
    use MageOS\TemplateParser\Test\HostDeferralFixtures\Registry;
    use MageOS\TemplateParser\Test\HostDeferralFixtures\RegistryFilter;
    use MageOS\TemplateParser\Test\HostDeferralFixtures\SignatureFilter;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    /**
     * Where the host's filter resolves an include's deferred {{inlinecss}}, and what compatible
     * mode does about the filters that inline a stylesheet over one body of the template.
     *
     * The corpus side of this is DeferralParityTest; this is the detection and the Magento layer.
     */
    final class HostDeferralTest extends TestCase
    {
        private const PARTIAL = 'stylesheet the filter inlines over part of the document';

        private const STYLED = '{{inlinecss file="css/a.css"}}<p>{{var a}}</p>';

        public function testAFilterTakingTheRegistryResolvesAtTheRoot(): void
        {
            self::assertTrue(HostDeferral::resolvesAtRoot(RegistryFilter::class, Registry::class));
        }

        public function testAFilterWithoutItDoesNot(): void
        {
            self::assertFalse(HostDeferral::resolvesAtRoot(SignatureFilter::class, Registry::class));
        }

        /** The class existing is not the signal: only the filter taking it is. */
        public function testTheRegistryClassAloneIsNotEnough(): void
        {
            self::assertTrue(class_exists(Registry::class));
            self::assertFalse(HostDeferral::resolvesAtRoot(SignatureFilter::class, Registry::class));
        }

        /** No filter to ask is the cautious answer, which only ever declines more. */
        public function testAMissingFilterAnswersBelowTheRoot(): void
        {
            self::assertFalse(HostDeferral::resolvesAtRoot('No\\Such\\Filter', Registry::class));
        }

        public function testTheTestStubFilterIsTheOlderShape(): void
        {
            self::assertFalse(HostDeferral::resolvesAtRoot());
        }

        /** @return array<string,array{0:string,1:bool,2:bool}> template, at root, declined */
        public static function placements(): array
        {
            $include = '{{template config_path="design/email/styled"}}';
            return [
                'include at top, 3.5'          => ["<html>$include</html>", false, false],
                'include at top, 3.6'          => ["<html>$include</html>", true, false],
                'include in if, 3.5'           => ["[{{if a}}$include{{/if}}]", false, true],
                'include in if, 3.6'           => ["[{{if a}}$include{{/if}}]", true, false],
                'include in else, 3.5'         => ["[{{if nope}}A{{else}}$include{{/if}}]", false, true],
                'include in depend, 3.5'       => ["[{{depend a}}$include{{/depend}}]", false, true],
                'include in two bodies, 3.6'   => ["[{{if a}}{{depend a}}$include{{/depend}}{{/if}}]", true, false],
                'nested include in if, 3.5'    => ['[{{if a}}{{template config_path="design/email/nested"}}{{/if}}]', false, true],
                'body inside include, 3.5'     => ['{{template config_path="design/email/cond"}}', false, false],
                'direct at top, 3.5'           => ['{{inlinecss file="css/a.css"}}X', false, false],
                'direct in if, 3.5'            => ['[{{if a}}{{inlinecss file="css/a.css"}}{{/if}}]', false, true],
                'direct in if, 3.6'            => ['[{{if a}}{{inlinecss file="css/a.css"}}{{/if}}]', true, true],
                'direct in depend, 3.6'        => ['[{{depend a}}{{inlinecss file="css/a.css"}}{{/depend}}]', true, true],
            ];
        }

        #[DataProvider('placements')]
        public function testTheAdapterDeclinesWhereTheFilterInlinesOverABody(string $template, bool $atRoot, bool $declined): void
        {
            $adapter = new TemplateFilterAdapter(new HostServices(templates: self::loader()), deferralResolvedAtRoot: $atRoot);
            $adapter->setVariables(['a' => 'x'])->filter($template);

            self::assertSame(
                $declined,
                in_array(self::PARTIAL, array_map(static fn ($v) => $v->kind, $adapter->violations()), true)
            );
            // Declined or not, the stylesheet is still handed to the host: the decline is
            // about where legacy puts it, and Shadow and `check` still see what was asked.
            self::assertNotSame([], $adapter->deferred());
        }

        /** In Parser mode a decline is a fallback to the filter, which places it as it always did. */
        public function testADeclinedRenderIsAPolicyRefusal(): void
        {
            $comparator = new ShadowComparator(
                new TemplateFilterAdapter(new HostServices(templates: self::loader()), deferralResolvedAtRoot: false)
            );

            $outcome = $comparator->render(
                '[{{if a}}{{template config_path="design/email/styled"}}{{/if}}]',
                ['a' => 'x'],
                finish: static fn (string $html, array $files): string => implode(',', $files) . $html
            );

            self::assertInstanceOf(ShadowOutcome::class, $outcome);
            self::assertSame(ShadowOutcome::REFUSED, $outcome->outcome);
            self::assertStringContainsString(self::PARTIAL, implode("\n", $outcome->detail['policy_violations']));
        }

        /** A branch not taken asks for nothing, so there is nothing to place. */
        public function testABranchNotTakenIsNotDeclined(): void
        {
            $adapter = new TemplateFilterAdapter(new HostServices(templates: self::loader()), deferralResolvedAtRoot: false);
            $adapter->setVariables(['a' => 'x'])->filter('[{{if nope}}{{inlinecss file="css/a.css"}}{{/if}}]');

            self::assertSame([], $adapter->violations());
            self::assertSame([], $adapter->deferred());
        }

        public function testATextBodyHasNoStylesheetToPlace(): void
        {
            $adapter = new TemplateFilterAdapter(new HostServices(templates: self::loader()), deferralResolvedAtRoot: false);
            $adapter->setPlainTemplateMode(true)->setVariables(['a' => 'x'])->filter('[{{if a}}{{inlinecss file="css/a.css"}}{{/if}}]');

            self::assertSame([], $adapter->violations());
        }

        /** Only compatible mode claims the filter's output; the other postures place it their own way. */
        public function testOnlyCompatibleModeDeclines(): void
        {
            $context = new Context(['a' => 'x']);
            TemplateEngine::withOptions(Options::lenient()->withDeferralResolvedAtRoot(false))
                ->render('[{{if a}}{{inlinecss file="css/a.css"}}{{/if}}]', context: $context);

            self::assertSame([], $context->violations());
            self::assertCount(1, $context->deferred());
        }

        public function testCompatibleModeTargetsTheCurrentFilterByDefault(): void
        {
            self::assertTrue(Options::compatible()->deferralResolvedAtRoot);
            self::assertFalse(Options::compatible()->withDeferralResolvedAtRoot(false)->deferralResolvedAtRoot);
        }

        /** A loop scope is the same template: its body depth carries into each iteration. */
        public function testALoopInsideABodyKeepsItsPosition(): void
        {
            $context = new Context(['a' => 'x', 'items' => [['n' => 1]]]);
            TemplateEngine::withOptions(Options::compatible())
                ->render('[{{if a}}{{for i in items}}{{inlinecss file="css/a.css"}}{{/for}}{{/if}}]', context: $context);

            self::assertSame([self::PARTIAL], array_map(static fn ($v) => $v->kind, $context->violations()));
        }

        private static function loader(): TemplateLoader
        {
            return new class implements TemplateLoader {
                public function load(string $configPath): ?string
                {
                    return [
                        'design/email/styled' => HostDeferralTest::styled(),
                        'design/email/nested' => '<div>{{template config_path="design/email/styled"}}</div>',
                        'design/email/cond' => '{{if a}}{{inlinecss file="css/a.css"}}{{/if}}<p>{{var a}}</p>',
                    ][$configPath] ?? null;
                }
            };
        }

        public static function styled(): string
        {
            return self::STYLED;
        }
    }
}
