<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento\Plugin;

use Cresset\TemplateParser\Magento\Shadow\TemplateIdentity;
use Magento\Newsletter\Model\Template;

/**
 * Names a newsletter template's SUBJECT before it is filtered, as `newsletter:<id>/subject`.
 *
 * The newsletter model declares its own `getProcessedTemplateSubject()` - it extends
 * AbstractTemplate, not the email model the email subject plugin sits on - so it needs a plugin
 * of its own. This is the path the admin's preview and test send take; a queued send renders
 * through the email model instead, and NewsletterQueueIdentityPlugin names that one.
 */
class NewsletterSubjectIdentityPlugin
{
    public function __construct(private readonly TemplateIdentity $identity)
    {
    }

    /**
     * @param array<string,mixed> $variables
     */
    public function beforeGetProcessedTemplateSubject(Template $subject, array $variables): void
    {
        try {
            $this->identity->remember(
                (string)$subject->getTemplateSubject(),
                EmailTemplateIdentityPlugin::name($subject, '/subject')
            );
        } catch (\Throwable) {
            // Naming is a nicety; the render it precedes is not.
        }
    }
}
