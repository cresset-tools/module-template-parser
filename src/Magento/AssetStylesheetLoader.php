<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Magento;

use MageOS\TemplateParser\Port\StylesheetLoader;

/** {{css}} through Magento's CSS processor. */
final class AssetStylesheetLoader implements StylesheetLoader
{
    public function __construct(
        private readonly \Magento\Email\Model\Template\Css\Processor $cssProcessor,
        private readonly \Magento\Framework\View\Asset\Repository $assetRepository
    ) {
    }

    public function load(string $file): ?string
    {
        try {
            $asset = $this->assetRepository->createAsset($file);
            $css = $this->cssProcessor->process((string)$asset->getContent());
        } catch (\Throwable) {
            return null;
        }

        return $css === '' ? null : $css;
    }
}
