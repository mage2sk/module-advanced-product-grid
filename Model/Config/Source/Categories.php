<?php
/**
 * Cached tree-flattened category options source. Cached against the
 * config + category cache tag so saving any category busts the value.
 *
 * Returned shape is what the JS multi-select expects:
 *   [['value' => '1/2', 'label' => 'Root > Default Category']]
 */
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Model\Config\Source;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Option\ArrayInterface;
use Magento\Store\Model\StoreManagerInterface;

class Categories implements ArrayInterface
{
    private const CACHE_KEY = 'panth_product_grid_categories_';
    private const CACHE_TAGS = ['catalog_category', 'config_scopes'];

    public function __construct(
        private readonly CollectionFactory $categoryCollectionFactory,
        private readonly CacheInterface $cache,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function toOptionArray(): array
    {
        $storeId = (int)$this->storeManager->getStore()->getId();
        $key = self::CACHE_KEY . $storeId;
        $cached = $this->cache->load($key);
        if ($cached !== false) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToSelect('name')
            ->addAttributeToSelect('path')
            ->setStore($storeId)
            ->addFieldToFilter('level', ['gt' => 1])
            ->setOrder('path', 'ASC');

        $options = [['value' => '_none_', 'label' => __('No Categories')]];
        $nameById = [];
        foreach ($collection as $cat) {
            $nameById[(int)$cat->getId()] = (string)$cat->getName();
        }
        foreach ($collection as $cat) {
            $segments = explode('/', (string)$cat->getPath());
            $labels = [];
            foreach ($segments as $segId) {
                if ((int)$segId === 1) {
                    continue;
                }
                if (isset($nameById[(int)$segId])) {
                    $labels[] = $nameById[(int)$segId];
                }
            }
            $options[] = [
                'value' => (int)$cat->getId(),
                'label' => implode(' > ', $labels),
            ];
        }

        $this->cache->save(json_encode($options), $key, self::CACHE_TAGS, 86400);
        return $options;
    }
}
