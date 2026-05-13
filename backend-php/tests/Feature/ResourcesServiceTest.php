<?php

namespace Tests\Feature;

use App\Domain\Enums\ResourceType;
use App\Exceptions\NotFoundDomainException;
use App\Services\ResourcesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class ResourcesServiceTest extends TestCase
{
    use RefreshDatabase;

    private ResourcesService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ResourcesService::class);
    }

    public function test_creates_and_reads(): void
    {
        $created = $this->service->create([
            'identifier' => 'K-100',
            'type' => ResourceType::WW_KAYAK->value,
            'name' => 'Kayak',
        ]);
        $fetched = $this->service->findById($created->id);
        $this->assertSame('K-100', $fetched->identifier);
    }

    public function test_throws_not_found_on_missing_id(): void
    {
        $this->expectException(NotFoundDomainException::class);
        $this->service->findById(Uuid::uuid4()->toString());
    }

    public function test_deactivate(): void
    {
        $created = $this->service->create([
            'identifier' => 'K-101',
            'type' => ResourceType::WW_KAYAK->value,
            'name' => 'Kayak',
        ]);
        $result = $this->service->setActive($created->id, false);
        $this->assertFalse((bool) $result->isActive);
    }

    public function test_list_filters_by_type_and_search(): void
    {
        $this->service->create([
            'identifier' => 'K-200',
            'type' => ResourceType::WW_KAYAK->value,
            'name' => 'WW Pyranha',
            'model' => 'Burn',
        ]);
        $this->service->create([
            'identifier' => 'C-200',
            'type' => ResourceType::CANOE->value,
            'name' => 'Old Town',
        ]);

        $result = $this->service->list([
            'type' => ResourceType::WW_KAYAK,
            'take' => 25,
        ]);
        $this->assertSame(1, $result['total']);
        $this->assertSame('K-200', $result['items']->first()->identifier);

        $byName = $this->service->list(['search' => 'pyranha']);
        $this->assertSame(1, $byName['total']);
    }
}
