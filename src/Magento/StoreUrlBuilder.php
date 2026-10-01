<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento;

use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Cresset\TemplateParser\Port\UrlBuilder;

/**
 * {{store}}, {{media}}, {{view}} and {{protocol}} through Magento's URL and asset services.
 *
 * Paths reaching these have already been through PathGuard, so no traversal, scheme or
 * absolute path arrives here.
 */
class StoreUrlBuilder implements UrlBuilder
{
    public function __construct(
        private readonly UrlInterface $urlModel,
        private readonly StoreManagerInterface $storeManager,
        private readonly \Magento\Framework\View\Asset\Repository $assetRepository
    ) {
    }

    /** @param array<string,string|null> $parameters */
    public function storeUrl(string $path, array $parameters): string
    {
        $query = [];
        foreach ($parameters as $key => $value) {
            if (str_starts_with($key, '_query_')) {
                $query[substr($key, 7)] = $value;
                unset($parameters[$key]);
            }
        }
        $parameters['_query'] = $query;
        $parameters['_nosid'] = true;
        // storeDirective OVERWRITES this with the store code rather than reading it, and the
        // difference matters: Url escapes route parameters only while it is truthy, so a
        // template that forwarded its own `_escape_params=0` would turn the escaping off for
        // every route parameter it also supplied.
        $store = $this->storeManager->getStore();
        $parameters['_escape_params'] = $store->getCode();

        // storeDirective sets the scope on every call, and so must this. A URL model's scope
        // is sticky - Url::_getScope() resolves it once and keeps it - and the instance is
        // shared, so without this a store view's email is built with whichever store last set
        // it: a cron run sending for store 1 and then store 2 would link store 2's customers
        // to store 1. Legacy skips it for the backend URL model, and so does this.
        if (!$this->urlModel instanceof \Magento\Backend\Model\Url) {
            $this->urlModel->setScope($store);
        }

        return $this->urlModel->getUrl($path, $parameters);
    }

    public function mediaUrl(string $path): string
    {
        return $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA) . $path;
    }

    /** @param array<string,string|null> $parameters */
    public function viewUrl(string $path, array $parameters): string
    {
        return $this->assetRepository->getUrlWithParams($path, $parameters);
    }

    public function isSecure(): bool
    {
        return $this->storeManager->getStore()->isCurrentlySecure();
    }
}
