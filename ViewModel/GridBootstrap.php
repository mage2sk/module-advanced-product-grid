<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\ViewModel;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class GridBootstrap implements ArgumentInterface
{
    private const BASE_POPUP_CODES = [
        'tier_price', 'panth_tier_price_label',
        'category_ids', 'panth_categories',
        'description', 'short_description',
        'content', 'content_heading', 'page_content', 'block_content',
        'thumbnail', 'image', 'small_image', 'panth_thumbnail',
    ];

    public function __construct(
        private readonly MediaConfig $mediaConfig,
        private readonly EavConfig $eavConfig,
        private readonly AttributeCollectionFactory $attributeCollectionFactory
    ) {
    }

    public function getMediaUrl(): string
    {
        return (string)$this->mediaConfig->getMediaUrl('');
    }

    public function getPopupCodes(): array
    {
        $codes = self::BASE_POPUP_CODES;
        try {
            $entityType = $this->eavConfig->getEntityType(Product::ENTITY);
            $collection = $this->attributeCollectionFactory->create();
            $collection->setEntityTypeFilter((int)$entityType->getId())
                ->addFieldToFilter('frontend_input', ['in' => ['multiselect', 'textarea']]);
            foreach ($collection as $attribute) {
                $codes[] = (string)$attribute->getAttributeCode();
            }
        } catch (\Throwable) {
            return self::BASE_POPUP_CODES;
        }
        return array_values(array_unique($codes));
    }
}
