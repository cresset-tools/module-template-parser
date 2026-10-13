<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Magento\Plugin;

use MageOS\TemplateParser\Magento\Shadow\TemplateIdentity;
use Magento\Catalog\Helper\Output;

/**
 * Names product and category attribute content as the storefront renders it, as
 * `product:<id>/<attribute>` and `category:<id>/<attribute>`.
 *
 * `Catalog\Helper\Output` sends a WYSIWYG attribute that holds a directive to the template
 * filter unchanged - the Widget filter, which extends the Email one this module plugs - so the
 * text handed in here is the source the filter plugin will look up. Without this every
 * description with a {{widget}} or {{media}} in it is `unidentified`.
 *
 * Only text that holds a directive is remembered. productAttribute() is called for every
 * attribute a product page shows, and remembering each would push the templates that are
 * actually filtered out of TemplateIdentity's bounded memory.
 */
class CatalogOutputIdentityPlugin
{
    public function __construct(private readonly TemplateIdentity $identity)
    {
    }

    /**
     * @param mixed $product
     * @param mixed $attributeHtml
     * @param mixed $attributeName
     * @return null
     */
    public function beforeProductAttribute(Output $subject, $product, $attributeHtml, $attributeName)
    {
        $this->remember('product', $product, $attributeHtml, $attributeName);

        return null;
    }

    /**
     * @param mixed $category
     * @param mixed $attributeHtml
     * @param mixed $attributeName
     * @return null
     */
    public function beforeCategoryAttribute(Output $subject, $category, $attributeHtml, $attributeName)
    {
        $this->remember('category', $category, $attributeHtml, $attributeName);

        return null;
    }

    /** @param mixed $entity @param mixed $html @param mixed $attribute */
    private function remember(string $kind, $entity, $html, $attribute): void
    {
        if (!is_string($html) || !str_contains($html, '{{') || !is_object($entity) || !method_exists($entity, 'getId')) {
            return;
        }

        $this->identity->remember($html, sprintf('%s:%s/%s', $kind, (string)$entity->getId(), (string)$attribute));
    }
}
