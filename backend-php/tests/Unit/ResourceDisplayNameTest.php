<?php

namespace Tests\Unit;

use App\Domain\Enums\ResourceType;
use App\Models\Resource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Boats show "Výrobca Model"; the `name` column holds the manufacturer.
 * Existing records are messy (model repeated inside the name, only one of
 * the two filled in), so composing must never double a word or lose data.
 */
class ResourceDisplayNameTest extends TestCase
{
    /** @return array<string, array{ResourceType, string, ?string, string}> */
    public static function cases(): array
    {
        return [
            'manufacturer + model' => [ResourceType::WW_KAYAK, 'Dagger', 'Nomad', 'Dagger Nomad'],
            'model already inside the name' => [ResourceType::SEA_KAYAK, 'SeaBird HV', 'SeaBird HV', 'SeaBird HV'],
            'name is part of the model' => [ResourceType::SEA_KAYAK, 'Prijon', 'Prijon Marlin', 'Prijon Marlin'],
            'model contained, case-insensitive' => [ResourceType::CANOE, 'viva', 'VIVA', 'viva'],
            'no model' => [ResourceType::CANOE, 'Samba', null, 'Samba'],
            'blank model' => [ResourceType::INFLATABLE_BOAT, 'Gumo', '  ', 'Gumo'],
            'only a model' => [ResourceType::ROWING_BOAT, '', 'Pramica', 'Pramica'],
            'trailer ignores the model' => [ResourceType::TRAILER, 'Príves malý modrý', 'Klarex', 'Príves malý modrý'],
            'space ignores the model' => [ResourceType::BOATHOUSE_SPACE, 'Nová lodenica', 'x', 'Nová lodenica'],
            'diacritics' => [ResourceType::WW_KAYAK, 'Železný', 'Bert', 'Železný Bert'],
        ];
    }

    #[DataProvider('cases')]
    public function test_compose(ResourceType $type, string $name, ?string $model, string $expected): void
    {
        $this->assertSame($expected, Resource::composeDisplayName($type, $name, $model));
    }
}
