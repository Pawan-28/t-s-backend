<?php

namespace App\Services\Import;

/**
 * Global (cross-row) decisions computed by the PreImportValidator and applied
 * verbatim by the importers. Per-row rules (enum defaults, FK nulling) are
 * applied by the importers through App\Services\Import\Support\Rules.
 */
class ResolutionPlan
{
    /** @var array<int, string> user id => final (lower-case) email */
    public array $emails = [];

    /** @var array<int, true> user id => import inactive (non-primary duplicate / placeholder email) */
    public array $deactivate = [];

    /** @var array<int, ?string> user id => final phone (null = cleared) */
    public array $phones = [];

    /** @var array<string, array<int, string>> entity => [id => final slug] */
    public array $slugs = [];

    /** @var array<string, array<int, string>> "entity.column" => [id => final value] for unique text columns (names, gateway ids) that collide under the target collation */
    public array $values = [];

    /** @var array<int, int> article id => image id that is the featured one */
    public array $featured = [];

    /** @var array<int, true> publishing schedule ids demoted PENDING -> CANCELLED */
    public array $scheduleCancel = [];

    public function slug(string $entity, int $id, ?string $fallback): ?string
    {
        return $this->slugs[$entity][$id] ?? $fallback;
    }

    /** Final value of a unique text column (unchanged unless the validator planned a replacement). */
    public function value(string $entity, string $column, int $id, ?string $fallback): ?string
    {
        return $this->values[$entity.'.'.$column][$id] ?? $fallback;
    }
}
