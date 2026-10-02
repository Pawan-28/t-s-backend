<?php

namespace App\Services\Import\Entities;

use App\Services\Import\ImportContext;
use App\Services\Import\Support\Rules;

/** All placements (incl. HOME_TOP) and creative URLs/paths kept. `creative_type` (always IMAGE) has no target column. */
class AdvertisementImporter extends AbstractEntityImporter
{
    protected const COLUMNS = ['id', 'name', 'placement', 'image_url', 'bunny_storage_path', 'target_url', 'start_at', 'end_at', 'is_active', 'priority', 'created_at', 'updated_at'];

    public function name(): string
    {
        return 'advertisements';
    }

    public function sourceTable(): string
    {
        return 'advertisements_advertisement';
    }

    public function optional(): bool
    {
        return true;
    }

    public function transform(array $row, ImportContext $ctx): ?array
    {
        $id = (int) $row['id'];
        $placement = Rules::PLACEMENT_RENAMES[$row['placement']] ?? $row['placement'];
        if (! in_array($placement, Rules::placements(), true)) {
            $ctx->skip('advertisements', $id, 'ADS-PLACEMENT-INVALID', 'placement is not a known value');

            return null;
        }

        return [
            'id' => $id,
            'name' => $row['name'],
            'placement' => $placement,
            'image_url' => self::str($row['image_url']),
            'bunny_storage_path' => self::str($row['bunny_storage_path']),
            'target_url' => self::str($row['target_url']),
            'start_at' => $row['start_at'],
            'end_at' => $row['end_at'],
            'is_active' => self::bool($row['is_active']),
            'priority' => (int) $row['priority'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
