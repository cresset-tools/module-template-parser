<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use MageOS\TemplateParser\Magento\DirectiveMethods;
use Magento\Framework\Interception\PluginListInterface;
use PHPUnit\Framework\TestCase;

/**
 * Which directive methods run code Magento does not ship - the rule the plugin declines by
 * and `template:check` reports by.
 *
 * The case that matters is a generated Interceptor: the on-demand generator redeclares every
 * public method, plugged or not, so a declaration alone says nothing.
 */
final class DirectiveMethodsTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/fixtures/LegacyOnlyDirectives.php';
    }

    /** @param array<string,array<string,mixed>> $chain "method:code" => what getNext() answers */
    private static function listAnswering(array $chain): PluginListInterface
    {
        return new class ($chain) implements PluginListInterface {
            public function __construct(private array $chain) {}
            public function getPlugin($type, $code) { return null; }
            public function getNext($type, $method, $code = '__self')
            {
                return $this->chain[$type . '::' . $method . ':' . $code] ?? null;
            }
        };
    }

    /** All sixteen stock directives redeclared, one plugged: only that one is the filter's. */
    public function testAnOnDemandInterceptorReportsOnlyThePluggedDirective(): void
    {
        $subject = new \Magento\Email\Model\Template\Filter\Interceptor(['customvarDirective']);

        self::assertSame(
            ['customvar' => [
                'kind' => DirectiveMethods::PLUGGED,
                'class' => \Magento\Email\Model\Template\Filter::class,
                'plugins' => ['some_module_plugin'],
            ]],
            DirectiveMethods::of($subject::class, null, $subject)
        );
    }

    public function testAnOnDemandInterceptorWithNothingPluggedHasNothing(): void
    {
        $subject = new \Magento\Email\Model\Template\Filter\Interceptor();

        self::assertSame([], DirectiveMethods::of($subject::class, null, $subject));
    }

    /**
     * Without an instance - what template:check has - the plugin list given is asked, for the
     * class the Interceptor stands in for.
     */
    public function testTheGivenPluginListIsAskedForTheInterceptedClass(): void
    {
        $type = \Magento\Email\Model\Template\Filter::class;
        $list = self::listAnswering([$type . '::customvarDirective:__self' => ['after' => ['pagebuilder_customvar']]]);

        self::assertSame(
            ['customvar'],
            array_keys(DirectiveMethods::of(\Magento\Email\Model\Template\Filter\Interceptor::class, $list))
        );
    }

    /** getNext() stops at each around plugin; the rest of the chain is asked for from there. */
    public function testEveryPluginOnTheChainIsNamed(): void
    {
        $type = \Magento\Email\Model\Template\Filter::class;
        $list = self::listAnswering([
            $type . '::varDirective:__self' => ['before' => ['a_before'], 'around' => 'b_around'],
            $type . '::varDirective:b_around' => ['after' => ['c_after']],
        ]);

        self::assertSame(
            ['a_before', 'b_around', 'c_after'],
            DirectiveMethods::of(\Magento\Email\Model\Template\Filter\Interceptor::class, $list)['var']['plugins']
        );
    }

    /**
     * A module's preference subclass gets an Interceptor of its own, `Vendor\...\Interceptor`,
     * redeclaring every stock directive it inherits. Those are still Magento's; only the
     * module's own method is reported.
     */
    public function testAnInterceptorOverAModulesSubclassReportsOnlyTheModulesMethod(): void
    {
        self::assertSame(
            ['coupon' => ['kind' => DirectiveMethods::OWN, 'class' => \Acme\Promo\Filter::class, 'plugins' => []]],
            DirectiveMethods::of(\Acme\Promo\Filter\Interceptor::class, self::listAnswering([]))
        );
    }

    /**
     * The compiled generator redeclares what any plugin on the class names, in any area, so
     * a method plugged only in adminhtml is redeclared on the storefront too - unplugged there.
     */
    public function testARedeclaredMethodThePluginListHasNothingForIsStock(): void
    {
        self::assertSame(
            [],
            DirectiveMethods::of(\Magento\Widget\Model\Template\Filter\Interceptor::class, self::listAnswering([]))
        );
    }

    /** With no list to ask at all, an Interceptor's method is taken as plugged - what compiled output means. */
    public function testWithNoPluginListARedeclaredMethodIsTakenAsPlugged(): void
    {
        self::assertSame(
            ['var'],
            array_keys(DirectiveMethods::of(\Magento\Widget\Model\Template\Filter\Interceptor::class))
        );
    }

    public function testAnInterceptorStandsInForItsParent(): void
    {
        self::assertSame(\Acme\Promo\Filter::class, DirectiveMethods::originalClass(\Acme\Promo\Filter::class));
        self::assertSame(\Acme\Promo\Filter::class, DirectiveMethods::originalClass(\Acme\Promo\Filter\Interceptor::class));
    }
}
