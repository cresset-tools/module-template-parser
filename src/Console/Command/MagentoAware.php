<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console\Command;

use Cresset\TemplateParser\Console\MagentoContext;
use Cresset\TemplateParser\Console\Mode;
use Symfony\Component\Console\Input\InputInterface;
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

    /**
     * Layout handles this run may render.
     *
     * Off by default and it stays that way: a layout handle decides which blocks get built,
     * so template text is not a trustworthy source for one. Naming them is how {{layout}}
     * resolves at all - without any, the directive stays unregistered and every stock sales
     * email reports as a difference, which is honest but not useful when what you wanted was
     * to see the order table.
     *
     * @return static
     */
    protected function addLayoutOption(): static
    {
        $this->addOption(
            'allow-layout-handle',
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Layout handle {{layout}} may render; repeat for more, or "stock-email" for the ones stock emails use'
        );

        return $this;
    }

    /**
     * The handles the stock sales emails need, as a shorthand.
     *
     * Every one of these is already reachable from a template the store ships, so allowing
     * them grants nothing a stock installation does not already do.
     *
     * @return string[]
     */
    protected function layoutHandles(InputInterface $input): array
    {
        $handles = (array)$input->getOption('allow-layout-handle');

        if (in_array('stock-email', $handles, true)) {
            $handles = array_merge(array_diff($handles, ['stock-email']), [
                'sales_email_order_items',
                'sales_email_order_invoice_items',
                'sales_email_order_shipment_items',
                'sales_email_order_creditmemo_items',
            ]);
        }

        return array_values(array_unique(array_filter($handles, 'is_string')));
    }
}
