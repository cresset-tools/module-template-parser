<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console;

/**
 * Renders through the pipeline the store is running today.
 *
 * Through Magento's own template models, not a filter built by hand here. That distinction
 * turned out to matter more than anything else in this tool: a hand-built
 * Email\Model\Template\Filter has no design params, so every {{css}} raises "Design params
 * must be set"; nothing resolves a {{template}} include into a rendered child, so the
 * include's own directives leak into the parent as text and come back encoded by the output
 * neutralizer; and none of the store variables stock templates are written against exist,
 * so `{{trans "Your %store_name order" store_name=$store.frontend_name}}` renders "Your ".
 * All three showed up as divergences in nearly every stock template - none of them real.
 *
 * Each kind of template goes through the pipeline it goes through in production: an email
 * or newsletter through its template model, CMS content through the CMS filter provider.
 * Rendering a CMS block through the email model would give it store variables no CMS block
 * ever has.
 *
 * Returns null when there is no store, or when the pipeline raised: a template the current
 * filter cannot render has no output to compare against, which is itself worth reporting.
 */
class LegacyRenderer
{
    public function __construct(private readonly MagentoContext $magento)
    {
    }

    public function isAvailable(): bool
    {
        return $this->magento->isAvailable()
            && $this->magento->get(\Magento\Email\Model\TemplateFactory::class) !== null;
    }

    /** @param array<string,mixed> $variables */
    public function render(
        string $template,
        array $variables,
        ?int $storeId = null,
        string $kind = TemplateSubject::KIND_EMAIL,
    ): ?LegacyRender {
        // The legacy filter emits notices for exactly the constructs this tool exists to
        // find, so letting them through would bury the report in PHP warnings about
        // templates the report is already telling you about.
        set_error_handler(static fn (): bool => true);

        try {
            return $kind === TemplateSubject::KIND_CMS
                ? $this->renderCms($template)
                : $this->renderEmail($template, $variables, $storeId, $kind);
        } catch (\Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * An email or newsletter, through the model that sends it.
     *
     * getProcessedTemplate() is where the design config becomes design params, where
     * {{template}} includes become rendered child templates, and where addEmailVariables()
     * runs. Calling the filter directly skips all three.
     *
     * @param array<string,mixed> $variables
     */
    private function renderEmail(string $template, array $variables, ?int $storeId, string $kind): ?LegacyRender
    {
        $factory = $kind === TemplateSubject::KIND_NEWSLETTER
            ? $this->magento->get(\Magento\Newsletter\Model\TemplateFactory::class)
                ?? $this->magento->get(\Magento\Email\Model\TemplateFactory::class)
            : $this->magento->get(\Magento\Email\Model\TemplateFactory::class);

        if ($factory === null) {
            return null;
        }

        $model = $factory->create();
        $model->setTemplateType(\Magento\Framework\App\TemplateTypesInterface::TYPE_HTML);
        $model->setTemplateText($template);
        // Without a design config there is no theme to resolve {{css}} and {{view}} against,
        // and getDesignParams() throws rather than defaulting.
        $model->setDesignConfig([
            'area' => \Magento\Framework\App\Area::AREA_FRONTEND,
            'store' => $storeId ?? $this->currentStoreId(),
        ]);

        // Render once through the model and throw the result away. getProcessedTemplate() is
        // the only public path that configures the filter the way production configures it -
        // design params off the design config, the include processor, `this`, and the store
        // variables addEmailVariables() adds - and it does all of that inline, with no way to
        // ask for the setup on its own.
        try {
            $model->getProcessedTemplate($variables);
        } catch (\Throwable) {
            // A template the pipeline as a whole cannot render still has a filter worth
            // comparing, and the filter is what this tool is about.
        }

        $filter = $model->getTemplateFilter();

        // {{inlinecss}} is not rendering. The directive only collects stylesheets; the filter
        // rewrites the finished document through Emogrifier once the render is over, doctype
        // and all. This engine defers that step to its host rather than doing it, and a host
        // running this engine inside Magento still ends up in exactly this method - so hand
        // the same step back for the candidate render, or every stock template with a
        // stylesheet reports as a difference that switching engines would not cause.
        $finish = static function (string $candidate) use ($filter): string {
            try {
                return (string)$filter->applyInlineCss($candidate);
            } catch (\Throwable) {
                return $candidate;
            }
        };

        return new LegacyRender(
            (string)$filter->filter($template),
            $this->variablesUsedBy($model, $variables),
            method_exists($filter, 'applyInlineCss') ? $finish : null
        );
    }

    /**
     * CMS content, through the filter CMS content is rendered with.
     *
     * FilterProvider::getPageFilter() is what Cms\Block\Page and the widget renderers use.
     * It takes no variables - a CMS page has none - so there is nothing to read back.
     */
    private function renderCms(string $template): ?LegacyRender
    {
        $provider = $this->magento->get(\Magento\Cms\Model\Template\FilterProvider::class);
        if ($provider === null) {
            return null;
        }

        return new LegacyRender((string)$provider->getPageFilter()->filter($template), []);
    }

    /**
     * The variables the model ended up handing its filter.
     *
     * addEmailVariables() adds `store`, `logo_url`, `store_phone` and about a dozen more,
     * and getProcessedTemplate() puts the model itself in `this`. It is protected, and
     * listing what it adds here would be a second implementation of it - one that drifts
     * silently the first time Magento adds a variable. So read back what was actually used.
     *
     * Filter\Template::$templateVars is protected with no getter and nothing else exposes
     * the set, hence the reflection. If it fails the caller's own variables stand, which
     * costs the comparison some variables rather than the comparison itself.
     *
     * @param array<string,mixed> $fallback
     * @return array<string,mixed>
     */
    private function variablesUsedBy(object $model, array $fallback): array
    {
        try {
            $property = new \ReflectionProperty(\Magento\Framework\Filter\Template::class, 'templateVars');
            $used = $property->getValue($model->getTemplateFilter());

            return is_array($used) && $used !== [] ? $used : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function currentStoreId(): ?int
    {
        $stores = $this->magento->get(\Magento\Store\Model\StoreManagerInterface::class);

        try {
            return $stores === null ? null : (int)$stores->getStore()->getId();
        } catch (\Throwable) {
            return null;
        }
    }
}
