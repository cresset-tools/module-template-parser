<?php
declare(strict_types=1);

/**
 * Renders a store's real emails, CMS blocks and CMS pages, and writes {name: output} as JSON.
 *
 *   AREA=adminhtml php tools/render-store.php OUT.json [name-prefix]   (from inside a store)
 *
 * Emails go through the path a sender takes - Mail\Template\FactoryInterface, then
 * processTemplate() and getSubject() - with the variables OrderSender, InvoiceSender,
 * ShipmentSender and CreditmemoSender build, for every order in the store. Blocks render
 * through Cms\Block\Block, pages through the page filter. Run it once per stage and compare
 * the files: that is how Parser mode was verified byte for byte against Legacy.
 *
 * Two things to know when comparing:
 *
 * - admin-area renders carry per-session secret keys (`key/<64 hex>`, also base64-encoded
 *   inside `uenc`), which differ between processes;
 * - CatalogWidget's ProductsList caches its HTML without the area in its key, so a storefront
 *   render after an admin one serves what the admin wrote. Legacy does that too, and is not
 *   reproducible across runs in that state. Flush the cache between a stage's runs, or mask
 *   long tokens and compare against the same sequence run under legacy.
 */

// Inert unless invoked directly - see tools/harness.php.
if (PHP_SAPI !== 'cli' || realpath($_SERVER['argv'][0] ?? '') !== __FILE__) {
    return;
}

use Magento\Framework\App\Bootstrap;

require getcwd() . '/app/bootstrap.php';
$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode(getenv('AREA') ?: 'frontend');
$om->get(\Magento\Framework\App\ObjectManager\ConfigLoader::class);
$om->configure($om->get(\Magento\Framework\ObjectManager\ConfigLoaderInterface::class)->load(getenv('AREA') ?: 'frontend'));

$out = [];
$prefix = $argv[2] ?? '';
$factory = $om->get(\Magento\Framework\Mail\Template\FactoryInterface::class);
$renderer = $om->get(\Magento\Sales\Model\Order\Address\Renderer::class);
$paymentHelper = $om->get(\Magento\Payment\Helper\Data::class);
$scope = $om->get(\Magento\Framework\App\Config\ScopeConfigInterface::class);

$email = function (string $name, string $configPath, int $storeId, array $vars) use (&$out, $factory, $scope, $prefix) {
    if ($prefix !== '' && !str_starts_with($name, $prefix)) {
        return;
    }
    $id = $scope->getValue($configPath, 'store', $storeId);
    $template = $factory->get($id)
        ->setVars($vars)
        ->setOptions(['area' => 'frontend', 'store' => $storeId]);
    try {
        $out[$name . ' body'] = $template->processTemplate();
        $out[$name . ' subject'] = (string)$template->getSubject();
    } catch (\Throwable $e) {
        $out[$name . ' ERROR'] = get_class($e) . ': ' . $e->getMessage();
    }
};

$orders = $om->get(\Magento\Sales\Model\ResourceModel\Order\CollectionFactory::class)->create();
foreach ($orders as $order) {
    $order = $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class)->get($order->getId());
    $storeId = (int)$order->getStoreId();
    $payment = '';
    try {
        $payment = $paymentHelper->getInfoBlockHtml($order->getPayment(), $storeId);
    } catch (\Throwable $e) {
        $payment = 'payment block failed: ' . $e->getMessage();
    }
    $base = [
        'order' => $order,
        'order_id' => $order->getId(),
        'billing' => $order->getBillingAddress(),
        'payment_html' => $payment,
        'store' => $order->getStore(),
        'formattedShippingAddress' => $order->getIsVirtual() ? null : $renderer->format($order->getShippingAddress(), 'html'),
        'formattedBillingAddress' => $renderer->format($order->getBillingAddress(), 'html'),
        'order_data' => [
            'customer_name' => $order->getCustomerName(),
            'is_not_virtual' => $order->getIsNotVirtual(),
            'email_customer_note' => $order->getEmailCustomerNote(),
            'frontend_status_label' => $order->getFrontendStatusLabel(),
        ],
    ];
    $guest = $order->getCustomerIsGuest() ? '_guest' : '';
    $n = 'order' . $order->getIncrementId();
    $email("$n new", "sales_email/order/{$guest}template", $storeId, $base + ['created_at_formatted' => $order->getCreatedAtFormatted(2)]);
    $email("$n comment", "sales_email/order_comment/{$guest}template", $storeId, $base + ['comment' => 'A comment']);
    foreach ($order->getInvoiceCollection() as $invoice) {
        $email("$n invoice", "sales_email/invoice/{$guest}template", $storeId, $base + ['invoice' => $invoice, 'invoice_id' => $invoice->getId(), 'comment' => '']);
    }
    foreach ($order->getShipmentsCollection() as $shipment) {
        $email("$n shipment", "sales_email/shipment/{$guest}template", $storeId, $base + ['shipment' => $shipment, 'shipment_id' => $shipment->getId(), 'comment' => '']);
    }
    foreach ($order->getCreditmemosCollection() as $creditmemo) {
        $email("$n creditmemo", "sales_email/creditmemo/{$guest}template", $storeId, $base + ['creditmemo' => $creditmemo, 'creditmemo_id' => $creditmemo->getId(), 'comment' => '']);
    }
}

$customers = $om->get(\Magento\Customer\Model\ResourceModel\Customer\CollectionFactory::class)->create();
foreach ($customers as $customer) {
    $data = $om->get(\Magento\Customer\Api\CustomerRepositoryInterface::class)->getById($customer->getId());
    $model = $om->create(\Magento\Customer\Model\Customer::class)->load($customer->getId());
    $store = $om->get(\Magento\Store\Model\StoreManagerInterface::class)->getStore((int)$data->getStoreId() ?: 1);
    $vars = ['customer' => $model, 'store' => $store, 'back_url' => ''];
    $email('customer new_account', 'customer/create_account/email_template', (int)$store->getId(), $vars);
    $email('customer forgot_password', 'customer/password/forgot_email_template', (int)$store->getId(), $vars);
    $email('customer reset_password', 'customer/password/reset_password_template', (int)$store->getId(), $vars);
}

if ($prefix === '' || str_starts_with($prefix, 'cms')) {
    $layout = $om->get(\Magento\Framework\View\LayoutInterface::class);
    $blocks = $om->get(\Magento\Cms\Model\ResourceModel\Block\CollectionFactory::class)->create();
    foreach ($blocks as $b) {
        if ($prefix !== '' && !str_starts_with('cms_block ' . $b->getIdentifier(), $prefix)) {
            continue;
        }
        try {
            $out['cms_block ' . $b->getIdentifier()] = $layout->createBlock(\Magento\Cms\Block\Block::class)
                ->setBlockId((int)$b->getId())->toHtml();
        } catch (\Throwable $e) {
            $out['cms_block ' . $b->getIdentifier() . ' ERROR'] = get_class($e) . ': ' . $e->getMessage();
        }
    }
    if ($prefix === '') {
        $filters = $om->get(\Magento\Cms\Model\Template\FilterProvider::class);
        foreach ($om->get(\Magento\Cms\Model\ResourceModel\Page\CollectionFactory::class)->create() as $p) {
            $page = $om->get(\Magento\Cms\Api\PageRepositoryInterface::class)->getById($p->getId());
            try {
                $out['cms_page ' . $page->getIdentifier()] = $filters->getPageFilter()->filter($page->getContent());
            } catch (\Throwable $e) {
                $out['cms_page ' . $page->getIdentifier() . ' ERROR'] = get_class($e) . ': ' . $e->getMessage();
            }
        }
    }
}

file_put_contents($argv[1], json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
fwrite(STDERR, count($out) . " renders\n");
