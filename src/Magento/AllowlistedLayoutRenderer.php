<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento;

use Magento\Framework\App\State;
use Magento\Framework\View\LayoutFactory;
use Cresset\TemplateParser\Port\LayoutRenderer;
use Cresset\TemplateParser\Port\RefusedByPort;
use Cresset\TemplateParser\PolicyViolation;

/**
 * {{layout}} restricted to handles the integrator has declared.
 *
 * A layout handle is a capability - it decides which blocks get built - and template text is
 * not a trustworthy source for one. The allowlist is required rather than optional: passing
 * null would mean any handle in the application is reachable from a template.
 */
class AllowlistedLayoutRenderer implements LayoutRenderer
{
    /**
     * The handles the stock sales emails build their item tables and tracking with.
     *
     * Every one is already reachable from a template the store ships, so allowing them grants
     * nothing a stock installation does not already do. etc/di.xml allows exactly these, and
     * the CLI's `--allow-layout-handle=stock-email` means them too.
     */
    public const STOCK_EMAIL_HANDLES = [
        'sales_email_order_items',
        'sales_email_order_invoice_items',
        'sales_email_order_shipment_items',
        'sales_email_order_shipment_track',
        'sales_email_order_creditmemo_items',
    ];

    /** @param string[] $allowedHandles */
    public function __construct(
        private readonly LayoutFactory $layoutFactory,
        private readonly State $appState,
        private readonly array $allowedHandles
    ) {
    }

    /**
     * Parameters that are a capability rather than data, and are dropped.
     *
     * `setDataUsingMethod('template', ...)` is `setTemplate()`, on EVERY block in the handle -
     * which is arbitrary .phtml execution from template text, the same escape closed in
     * LayoutBlockRenderer by dropping a non-frontend `area`. Legacy forwards it; this does
     * not. Dropped rather than refused, so the layout still renders with its own templates.
     */
    private const CAPABILITY_PARAMETERS = ['template', 'module_name'];

    /** @param array<string,string|null> $parameters */
    public function render(string $handle, string $area, array $parameters): string
    {
        if (!in_array($handle, $this->allowedHandles, true)) {
            // Declined out loud. A silent '' here is how Parser mode served every stock order
            // email without its item table: nothing recorded that the directive was skipped.
            throw new RefusedByPort(PolicyViolation::LAYOUT_HANDLE, $handle);
        }

        foreach (self::CAPABILITY_PARAMETERS as $key) {
            unset($parameters[$key]);
        }

        $render = function () use ($handle, $parameters): string {
            // `cacheable => false`, as emulateAreaCallback does: the blocks here are built
            // with per-render data, so letting them into the block cache would serve one
            // recipient's order to the next.
            $layout = $this->layoutFactory->create(['cacheable' => false]);
            $layout->getUpdate()->addHandle($handle)->load();
            $layout->generateXml();
            $layout->generateElements();

            // The parameters ARE the directive. `{{layout handle="sales_email_order_items"
            // order_id=$order_id}}` is how every stock order, invoice, shipment and credit
            // memo email builds its item table, and dropping them renders that table for no
            // order at all. Legacy sets them on every block in the handle, not just the root,
            // because the block that needs the id is generally a child.
            $rootBlock = null;
            foreach ($layout->getAllBlocks() as $block) {
                if ($rootBlock === null && !$block->getParentBlock()) {
                    $rootBlock = $block;
                }
                foreach ($parameters as $key => $value) {
                    $block->setDataUsingMethod($key, $value);
                }
            }

            // Without this getOutput() returns '' for any handle whose XML does not carry
            // output="1" - so the directive rendered nothing and looked like it worked.
            if ($rootBlock !== null) {
                $layout->addOutputElement($rootBlock->getNameInLayout());
            }

            $output = (string)$layout->getOutput();
            // https://bugs.php.net/bug.php?id=62468 - SimpleXML holds the layout's memory
            // until the cycle collector runs, which for a queue rendering thousands of emails
            // is the difference between steady and unbounded. Legacy does the same.
            $layout->__destruct();

            return $output;
        };

        return $area === $this->appState->getAreaCode()
            ? $render()
            : (string)$this->appState->emulateAreaCode($area, $render);
    }
}
