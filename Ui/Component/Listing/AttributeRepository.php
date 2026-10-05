<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Ui\Component\Listing;

use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Catalog\Api\Data\ProductAttributeInterface;

class AttributeRepository
{
    private const RENDERABLE_INPUTS = [
        'text', 'textarea', 'select', 'multiselect',
        'date', 'datetime', 'weight', 'price', 'boolean',
        'media_image',
    ];

    private const SKIP_ATTRIBUTES = [
        'msrp', 'msrp_display_actual_price_type',
        'options_container',
        'custom_design', 'custom_design_from', 'custom_design_to', 'custom_layout', 'custom_layout_update',
        'has_options', 'required_options',
        'image_label', 'small_image_label', 'thumbnail_label', 'swatch_image',
        'page_layout',
        'gift_message_available', 'gift_wrapping_available', 'gift_wrapping_price',
        'minimal_price', 'tax_class_id',
    ];

    private ?array $cached = null;

    public function __construct(
        private readonly AttributeRepositoryInterface $repository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    public function getEditableAttributes(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $criteria = $this->searchCriteriaBuilder
            ->addFilter('is_used_in_grid', 1)
            ->create();

        $items = $this->repository->getList(ProductAttributeInterface::ENTITY_TYPE_CODE, $criteria)
            ->getItems();

        $out = [];
        foreach ($items as $attr) {
            $code = (string)$attr->getAttributeCode();
            if (in_array($code, self::SKIP_ATTRIBUTES, true)) {
                continue;
            }
            if (!in_array((string)$attr->getFrontendInput(), self::RENDERABLE_INPUTS, true)) {
                continue;
            }
            $out[] = $attr;
        }
        return $this->cached = $out;
    }

    public function findByCode(string $code): ?AttributeInterface
    {
        if (in_array($code, self::SKIP_ATTRIBUTES, true)) {
            return null;
        }
        try {
            $attr = $this->repository->get(ProductAttributeInterface::ENTITY_TYPE_CODE, $code);
        } catch (\Throwable) {
            return null;
        }
        if (!in_array((string)$attr->getFrontendInput(), self::RENDERABLE_INPUTS, true)) {
            return null;
        }
        return $attr;
    }
}
