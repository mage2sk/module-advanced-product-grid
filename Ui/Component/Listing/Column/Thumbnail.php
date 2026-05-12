<?php
/**
 * Renders the thumbnail column with a click-to-open-gallery affordance.
 *
 * The cell content is the resolved image URL; the JS column component
 * recognises a `panth_action` field and routes clicks into the
 * thumbnail modal controller. When a product has no image, we fall
 * back to the catalog placeholder so the cell still renders a tile.
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Ui\Component\Listing\Column;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductFactory;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class Thumbnail extends Column
{
    public const FIELD_URL = 'panth_thumbnail_url';
    public const FIELD_ORIG = 'panth_thumbnail_orig';

    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly ImageHelper $imageHelper,
        private readonly ProductFactory $productFactory,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items']) || !is_array($dataSource['data']['items'])) {
            return $dataSource;
        }

        $fieldName = $this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            $product = $this->productFactory->create();
            $product->setData($item);
            $small = $this->imageHelper
                ->init($product, 'product_listing_thumbnail')
                ->getUrl();
            $orig = $this->imageHelper
                ->init($product, 'product_base_image')
                ->getUrl();
            $item[$fieldName . '_src'] = $small;
            $item[$fieldName . '_alt'] = (string)($item['name'] ?? '');
            $item[$fieldName . '_link'] = '#';
            $item[$fieldName . '_orig_src'] = $orig;
            $item[self::FIELD_URL] = $small;
            $item[self::FIELD_ORIG] = $orig;
        }

        return $dataSource;
    }
}
