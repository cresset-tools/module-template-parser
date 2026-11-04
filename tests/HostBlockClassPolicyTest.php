<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use Magento\Email\Model\Template\Filter\BlockDirectivePolicy;
use Magento\Framework\ObjectManagerInterface;
use MageOS\TemplateParser\Magento\HostBlockClassPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The {{block}} deny list is the host's where it has one, and absent where it does not.
 *
 * BlockDirectivePolicy exists only on Mage-OS hardened after 2026-09-08. Naming it in a
 * constructor made the module unbuildable everywhere else - every template render failed,
 * Legacy mode included - so it is looked up by name, and its absence restricts nothing.
 */
final class HostBlockClassPolicyTest extends TestCase
{
    /** A HostBlockClassPolicy whose object manager hands out $policy. */
    public static function around(object $policy): HostBlockClassPolicy
    {
        return new HostBlockClassPolicy(self::objectManager(static fn () => $policy));
    }

    /** @param \Closure(string): object $get */
    private static function objectManager(\Closure $get, array &$asked = []): ObjectManagerInterface
    {
        return new class ($get, $asked) implements ObjectManagerInterface {
            public function __construct(private \Closure $get, private array &$asked)
            {
            }

            public function create($type, array $arguments = [])
            {
                throw new \LogicException('not expected');
            }

            public function get($type)
            {
                $this->asked[] = $type;
                return ($this->get)($type);
            }

            public function configure(array $configuration)
            {
            }
        };
    }

    public function testTheHostsPolicyDecides(): void
    {
        $policy = self::around(new BlockDirectivePolicy(['\\Block\\Adminhtml\\']));

        self::assertTrue($policy->isRestricted('Magento\\Backend\\Block\\Adminhtml\\Thing'));
        self::assertFalse($policy->isRestricted('Magento\\Cms\\Block\\Block'));
    }

    /**
     * A host without the class: nothing is asked of the object manager, nothing is restricted,
     * and the integrator's allowlist in LayoutBlockRenderer is what still applies.
     */
    public function testATreeWithoutThePolicyRestrictsNothingAndAsksForNothing(): void
    {
        $asked = [];
        $policy = new HostBlockClassPolicy(
            self::objectManager(static fn () => throw new \LogicException('must not be built'), $asked),
            'Magento\\Email\\Model\\Template\\Filter\\NoSuchPolicyOnThisTree'
        );

        self::assertFalse($policy->isRestricted('Magento\\Backend\\Block\\Adminhtml\\Thing'));
        self::assertSame([], $asked);
    }

    /** A policy that cannot answer is not a licence to instantiate - on every call, not the first. */
    public function testAPolicyThatCannotBeBuiltRefusesEveryTime(): void
    {
        $policy = new HostBlockClassPolicy(self::objectManager(static fn () => throw new \RuntimeException('di broke')));

        self::assertTrue($policy->isRestricted('Magento\\Cms\\Block\\Block'));
        self::assertTrue($policy->isRestricted('Magento\\Cms\\Block\\Block'));
    }

    public function testAPolicyThatThrowsRefuses(): void
    {
        $policy = self::around(new class {
            public function isRestricted(string $class): bool
            {
                throw new \RuntimeException('pattern broke');
            }
        });

        self::assertTrue($policy->isRestricted('Magento\\Cms\\Block\\Block'));
    }
}
