<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\Information;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Variable\Model\Source\Variables;
use Cresset\TemplateParser\Port\ConfigReader;
use Cresset\TemplateParser\Port\RefusedByPort;

/**
 * {{config}} restricted to the variables Magento declares template-readable.
 *
 * This allowlist is the whole security of the directive: without it a template can read any
 * configuration value the store holds. Magento gates it with
 * Variables::getAvailableVars(); the same gate is applied here, plus a fallback deny if the
 * list cannot be obtained - failing closed rather than open.
 */
class AllowlistedConfigReader implements ConfigReader
{
    /**
     * @param ?Information $storeInformation resolves the two config paths that hold an ID but
     *        are rendered as a NAME. Optional so a host without Magento_Store's model still
     *        gets the directive, with the raw value it always had.
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Variables $availableVariables,
        private readonly ?int $storeId = null,
        private readonly ?Information $storeInformation = null,
        private readonly ?StoreManagerInterface $storeManager = null
    ) {
    }

    public function value(string $path): ?string
    {
        try {
            $allowed = $this->availableVariables->getAvailableVars();
        } catch (\Throwable) {
            // configDirective asks the same source, and would raise too.
            throw new RefusedByPort('config path', $path);
        }

        if (!in_array($path, $allowed, true)) {
            return null;
        }

        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $this->storeId);
        $value = is_scalar($value) ? (string)$value : null;

        return $this->named($path, $value);
    }

    /**
     * The two paths configDirective rewrites before returning them.
     *
     * `general/store_information/country_id` holds `NL` and renders `Netherlands`;
     * `region_id` holds a numeric id and renders the region's name. Returning the stored
     * value instead puts a country code in the footer of every email that uses the directive,
     * which is most of them.
     *
     * The asymmetry is legacy's and is reproduced: country is replaced UNCONDITIONALLY, so an
     * unresolvable one renders empty rather than falling back to the code, while region falls
     * back to the stored value when the lookup yields nothing.
     */
    private function named(string $path, ?string $value): ?string
    {
        // Most config reads are neither of these two paths and have no reason to load a store.
        if ($path !== Information::XML_PATH_STORE_INFO_COUNTRY_CODE
            && $path !== Information::XML_PATH_STORE_INFO_REGION_CODE
        ) {
            return $value;
        }

        // Without the services, or with a lookup that fails, the stored value is all there is -
        // `NL` where the filter renders `Netherlands`. That is less than legacy renders, so it
        // is refused rather than served.
        if ($this->storeInformation === null || $this->storeManager === null) {
            throw new RefusedByPort('config path (store information not wired)', $path);
        }

        try {
            $info = $this->storeInformation->getStoreInformationObject(
                $this->storeManager->getStore($this->storeId)
            );
        } catch (\Throwable) {
            throw new RefusedByPort('config path', $path);
        }

        if ($path === Information::XML_PATH_STORE_INFO_COUNTRY_CODE) {
            $country = $info->getData('country');
            return is_scalar($country) ? (string)$country : '';
        }

        $region = $info->getData('region');

        return is_scalar($region) && (string)$region !== '' ? (string)$region : $value;
    }
}
