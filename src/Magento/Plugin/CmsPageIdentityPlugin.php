<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento\Plugin;

use Cresset\TemplateParser\Magento\Shadow\TemplateIdentity;
use Magento\Cms\Model\Page;

/**
 * Names CMS page content as it is handed out, as `cms_page:<id>`.
 *
 * The CMS page renderers pass `getContent()` straight to the filter, so the text this
 * returns is the source the filter plugin will look up. One class per model, each typed as
 * exactly the class it is declared on, which is what Magento's plugin validator checks.
 */
class CmsPageIdentityPlugin
{
    public function __construct(private readonly TemplateIdentity $identity)
    {
    }

    /**
     * @param mixed $result
     * @return mixed
     */
    public function afterGetContent(Page $subject, $result)
    {
        if (is_string($result)) {
            $this->identity->remember($result, 'cms_page:' . (string)$subject->getId());
        }

        return $result;
    }
}
