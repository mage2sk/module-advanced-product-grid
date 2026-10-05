<?php
declare(strict_types=1);

namespace Panth\AdvancedProductGrid\Test\Unit\Model\InlineEdit;

use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\AdvancedProductGrid\Model\InlineEdit\AttributeSetAssigner;
use PHPUnit\Framework\TestCase;

class AttributeSetAssignerTest extends TestCase
{
    private array $queries = [];
    private array $inserts = [];
    private array $fetchQueue = [];
    private bool $insertThrows = false;
    private bool $attributeThrows = false;
    private ?int $attributeId = 55;

    private function assigner(): AttributeSetAssigner
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchOne')->willReturnCallback(function ($sql, $bind = []) {
            $this->queries[] = [preg_replace('/\s+/', ' ', $sql), $bind];
            return array_shift($this->fetchQueue);
        });
        $connection->method('insert')->willReturnCallback(function ($table, $data) {
            if ($this->insertThrows) {
                throw new \RuntimeException('duplicate');
            }
            $this->inserts[] = [$table, $data];
            return 1;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn ($t) => $t);

        $eav = $this->createStub(EavConfig::class);
        $eav->method('getAttribute')->willReturnCallback(function () {
            if ($this->attributeThrows) {
                throw new \RuntimeException('eav down');
            }
            $attribute = $this->createStub(AbstractAttribute::class);
            $attribute->method('getId')->willReturn($this->attributeId);
            $attribute->method('getEntityTypeId')->willReturn('4');
            return $attribute;
        });

        return new AttributeSetAssigner($resource, $eav);
    }

    public function testInvalidArgumentsDoNothing(): void
    {
        $assigner = $this->assigner();
        $assigner->ensure(0, 'color');
        $assigner->ensure(4, '');

        $this->assertSame([], $this->queries);
    }

    public function testUnknownOrBrokenAttributeIsIgnored(): void
    {
        $this->attributeId = null;
        $this->assigner()->ensure(4, 'missing');

        $this->attributeThrows = true;
        $this->assigner()->ensure(4, 'broken');

        $this->assertSame([], $this->queries);
        $this->assertSame([], $this->inserts);
    }

    public function testExistingAssignmentIsCachedForLaterCalls(): void
    {
        $this->fetchQueue = [123];
        $assigner = $this->assigner();

        $assigner->ensure(4, 'color');
        $assigner->ensure(4, 'color');

        $this->assertCount(1, $this->queries);
        $this->assertSame([4, 55], $this->queries[0][1]);
        $this->assertSame([], $this->inserts);
    }

    public function testMissingAssignmentIsInsertedIntoDefaultGroup(): void
    {
        $this->fetchQueue = [0, 17];
        $assigner = $this->assigner();

        $assigner->ensure(4, 'color');
        $assigner->ensure(4, 'color');

        $this->assertSame([[
            'eav_entity_attribute',
            [
                'entity_type_id' => 4,
                'attribute_set_id' => 4,
                'attribute_group_id' => 17,
                'attribute_id' => 55,
                'sort_order' => 100,
            ],
        ]], $this->inserts);
        $this->assertStringContainsString('default_id = 1', $this->queries[1][0]);
        $this->assertCount(2, $this->queries, 'second call is served from cache');
    }

    public function testFirstGroupIsUsedWhenSetHasNoDefaultGroup(): void
    {
        $this->fetchQueue = [0, 0, 21];

        $this->assigner()->ensure(9, 'color');

        $this->assertSame(21, $this->inserts[0][1]['attribute_group_id']);
        $this->assertStringContainsString('ORDER BY sort_order', $this->queries[2][0]);
    }

    public function testSetWithoutGroupsIsLeftAloneAndRetriedLater(): void
    {
        $this->fetchQueue = [0, 0, 0, 0, 0, 0];
        $assigner = $this->assigner();

        $assigner->ensure(9, 'color');
        $assigner->ensure(9, 'color');

        $this->assertSame([], $this->inserts);
        $this->assertCount(6, $this->queries);
    }

    public function testInsertFailureIsSwallowedAndCached(): void
    {
        $this->insertThrows = true;
        $this->fetchQueue = [0, 17];
        $assigner = $this->assigner();

        $assigner->ensure(4, 'color');
        $assigner->ensure(4, 'color');

        $this->assertCount(2, $this->queries);
    }
}
