<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Magento\Plugin;

use MageOS\TemplateParser\Magento\Shadow\TemplateIdentity;
use Magento\Customer\Block\Address\Renderer\DefaultRenderer;

/**
 * Names customer address renders as `address:<format code>` - html, text, oneline, pdf.
 *
 * renderArray() picks the format itself, from the country's own format when it has one, so
 * the text never passes a method a plugin could read it from without a second country load.
 * The name is held for the call instead, and the filter plugin uses it for the plain filter
 * FilterManager builds inside it.
 */
class AddressIdentityPlugin
{
    public function __construct(private readonly TemplateIdentity $identity)
    {
    }

    /**
     * @param mixed $addressAttributes
     * @param mixed $format
     * @return mixed
     */
    public function aroundRenderArray(DefaultRenderer $subject, callable $proceed, $addressAttributes, $format = null)
    {
        // A DataObject: getCode() is answered by __call, which method_exists() does not see.
        $type = $subject->getType();
        $code = is_object($type) && is_callable([$type, 'getCode']) ? (string)$type->getCode() : '';

        return $this->identity->during(
            'address:' . ($code !== '' ? $code : 'unknown'),
            static fn () => $proceed($addressAttributes, $format)
        );
    }
}
