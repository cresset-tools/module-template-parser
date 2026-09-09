<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console\Command;

use Cresset\TemplateParser\Console\MagentoContext;
use Cresset\TemplateParser\Console\Mode;
use Symfony\Component\Console\Input\InputOption;

/**
 * Shared wiring for commands that may or may not have a store.
 *
 * The context is injected when something else already booted Magento - n98-magerun2 does,
 * and booting a second time inside it would be wrong - and detected otherwise. Commands only
 * ever call magento(), so neither of them has to know which happened.
 */
trait MagentoAware
{
    private ?MagentoContext $injectedMagento = null;

    public function setMagentoContext(MagentoContext $context): static
    {
        $this->injectedMagento = $context;

        return $this;
    }

    protected function magento(): MagentoContext
    {
        return $this->injectedMagento ??= MagentoContext::detect();
    }

    protected function addModeOption(): static
    {
        $this->addOption(
            'mode',
            'm',
            InputOption::VALUE_REQUIRED,
            'Engine posture: ' . implode(', ', Mode::names()) . ' (legacy is a spelling of compatible)',
            Mode::Compatible->value
        );

        return $this;
    }
}
