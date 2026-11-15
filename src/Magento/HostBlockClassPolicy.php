<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Magento;

use Magento\Framework\ObjectManagerInterface;

/**
 * The store's own {{block}} deny list where the host has one, and nothing where it does not.
 *
 * Resolved lazily and by name, the way Magento_Email's own filter does it, so this class loads
 * on every Magento: on a tree without `BlockDirectivePolicy` it restricts nothing, which is the
 * behaviour that tree's filter has anyway, and the integrator's allowlist still applies.
 */
class HostBlockClassPolicy implements BlockClassPolicy
{
    public const HOST_POLICY = 'Magento\\Email\\Model\\Template\\Filter\\BlockDirectivePolicy';

    private ?object $policy = null;
    private bool $resolved = false;

    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly string $policyClass = self::HOST_POLICY
    ) {
    }

    /**
     * Any failure is a refusal - a policy that cannot answer is not a licence to instantiate.
     */
    public function isRestricted(string $class): bool
    {
        try {
            $policy = $this->policy();
            return $policy !== null && (bool)$policy->isRestricted($class);
        } catch (\Throwable) {
            return true;
        }
    }

    private function policy(): ?object
    {
        // Marked resolved only once it has been: a lookup that threw must refuse every time,
        // not just the first.
        if (!$this->resolved) {
            $this->policy = class_exists($this->policyClass)
                ? $this->objectManager->get($this->policyClass)
                : null;
            $this->resolved = true;
        }

        return $this->policy;
    }
}
