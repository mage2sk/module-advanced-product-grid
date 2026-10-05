<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Ui\Component;

use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Eav\Api\Data\AttributeSearchResultsInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Panth\AdvancedProductGrid\Ui\Component\Listing\AttributeRepository;
use PHPUnit\Framework\TestCase;

class AttributeRepositoryTest extends TestCase
{
    private int $listCalls = 0;
    private array $filters = [];

    private function attribute(string $code, string $input): AttributeInterface
    {
        $attribute = $this->createStub(AttributeInterface::class);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getFrontendInput')->willReturn($input);
        return $attribute;
    }

    private function repository(array $attributes): AttributeRepository
    {
        $results = $this->createStub(AttributeSearchResultsInterface::class);
        $results->method('getItems')->willReturn(array_values($attributes));
        $repo = $this->createStub(AttributeRepositoryInterface::class);
        $repo->method('getList')->willReturnCallback(function () use ($results) {
            $this->listCalls++;
            return $results;
        });
        $repo->method('get')->willReturnCallback(function ($entity, $code) use ($attributes) {
            if (!isset($attributes[$code])) {
                throw new NoSuchEntityException(__('missing'));
            }
            return $attributes[$code];
        });
        $builder = $this->createStub(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnCallback(function ($f, $v) use ($builder) {
            $this->filters[] = [$f, $v];
            return $builder;
        });
        $builder->method('create')->willReturn(new SearchCriteria());
        return new AttributeRepository($repo, $builder);
    }

    public function testEditableAttributesSkipBlockedAndUnrenderableOnesAndAreCached(): void
    {
        $attributes = [
            'name' => $this->attribute('name', 'text'),
            'tax_class_id' => $this->attribute('tax_class_id', 'select'),
            'gallery' => $this->attribute('gallery', 'gallery'),
            'image' => $this->attribute('image', 'media_image'),
            'color' => $this->attribute('color', 'multiselect'),
        ];
        $repository = $this->repository($attributes);

        $first = $repository->getEditableAttributes();
        $second = $repository->getEditableAttributes();

        $codes = array_map(static fn ($a) => $a->getAttributeCode(), $first);
        $this->assertSame(['name', 'image', 'color'], $codes);
        $this->assertSame($first, $second);
        $this->assertSame(1, $this->listCalls);
        $this->assertSame([['is_used_in_grid', 1]], $this->filters);
    }

    public function testFindByCodeHonoursSkipListAndRenderableInputs(): void
    {
        $repository = $this->repository([
            'name' => $this->attribute('name', 'text'),
            'msrp' => $this->attribute('msrp', 'price'),
            'gallery' => $this->attribute('gallery', 'gallery'),
        ]);

        $this->assertSame('name', $repository->findByCode('name')->getAttributeCode());
        $this->assertNull($repository->findByCode('msrp'));
        $this->assertNull($repository->findByCode('gallery'));
        $this->assertNull($repository->findByCode('unknown'));
    }
}
