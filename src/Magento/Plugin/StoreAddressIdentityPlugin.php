<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento\Plugin;

use Cresset\TemplateParser\Magento\Shadow\TemplateIdentity;
use Magento\Store\Model\Address\Renderer;

/**
 * Names the store's own address, as `store_address` - the one in email footers and on
 * invoices, rendered through FilterManager from a template the renderer holds privately.
 */
class StoreAddressIdentityPlugin
{
    public function __construct(private readonly TemplateIdentity $identity)
    {
    }

    /**
     * @param mixed $storeInfo
     * @param mixed $type
     * @return mixed
     */
    public function aroundFormat(Renderer $subject, callable $proceed, $storeInfo, $type = 'html')
    {
        return $this->identity->during('store_address', static fn () => $proceed($storeInfo, $type));
    }
}
