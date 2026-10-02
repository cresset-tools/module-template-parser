<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento\Plugin;

use Cresset\TemplateParser\Magento\Shadow\TemplateIdentity;
use Magento\Newsletter\Model\Queue;

/**
 * Names a queued newsletter's body and subject, as `newsletter_queue:<id>` and `.../subject`.
 *
 * A queued send does not render through the newsletter template at all. The queue copies the
 * text and subject at the time it was queued - editable on the queue afterwards - and hands
 * them to its transport builder, which builds a bare EMAIL model with no id. Left to the email
 * plugins that render would be named `email:unsaved`, indistinguishable from an admin preview,
 * on exactly the path that reaches every subscriber.
 *
 * The queue is the only thing that knows which newsletter this is, so it names the text before
 * sending; the email plugins do not overwrite a name with `unsaved` (TemplateIdentity::remember).
 */
class NewsletterQueueIdentityPlugin
{
    public function __construct(private readonly TemplateIdentity $identity)
    {
    }

    /**
     * @param int|string $count
     */
    public function beforeSendPerSubscriber(Queue $subject, $count = 20): void
    {
        try {
            $id = $subject->getId();
            $name = 'newsletter_queue:' . (is_scalar($id) && (string)$id !== '' ? (string)$id : 'unsaved');
            $this->identity->remember((string)$subject->getNewsletterText(), $name);
            $this->identity->remember((string)$subject->getNewsletterSubject(), $name . '/subject');
        } catch (\Throwable) {
            // Naming is a nicety; the send it precedes is not.
        }
    }
}
