<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento\Plugin;

use Cresset\TemplateParser\Magento\Shadow\TemplateIdentity;
use Magento\Cms\Model\Block;

/**
 * Names CMS block content as it is handed out, as `cms_block:<id>`.
 *
 * The CMS block renderers pass `getContent()` straight to the filter, so the text this
 * returns is the source the filter plugin will look up. One class per model, each typed as
 * exactly the class it is declared on, which is what Magento's plugin validator checks.
 */
class CmsBlockIdentityPlugin
{
    public function __construct(private readonly TemplateIdentity $identity)
    {
    }

    /**
     * @param mixed $result
     * @return mixed
     */
    public function afterGetContent(Block $subject, $result)
    {
        if (is_string($result)) {
            $this->identity->remember($result, 'cms_block:' . (string)$subject->getId());
        }

        return $result;
    }
}
