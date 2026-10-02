<?php

namespace App\Http\Resources;

use App\Models\Category;
use App\Models\Industry;
use App\Models\Subcategory;
use App\Models\Tag;

/** JSON shapes identical to the Django serializers (nested industry / category). */
class TaxonomyResources
{
    public static function industry(?Industry $i): ?array
    {
        return $i ? [
            'id' => $i->id, 'name' => $i->name, 'slug' => $i->slug, 'description' => $i->description,
            'is_active' => $i->is_active, 'display_order' => $i->display_order,
            'created_at' => $i->created_at?->toIso8601String(), 'updated_at' => $i->updated_at?->toIso8601String(),
        ] : null;
    }

    public static function category(?Category $c): ?array
    {
        return $c ? [
            'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'description' => $c->description,
            'image_url' => $c->image_url, 'image_storage_path' => $c->image_storage_path,
            'industry' => self::industry($c->industry),
            'is_active' => $c->is_active,
            'created_at' => $c->created_at?->toIso8601String(), 'updated_at' => $c->updated_at?->toIso8601String(),
        ] : null;
    }

    public static function subcategory(?Subcategory $s): ?array
    {
        return $s ? [
            'id' => $s->id, 'name' => $s->name, 'slug' => $s->slug, 'description' => $s->description,
            'category' => self::category($s->category),
            'is_active' => $s->is_active, 'display_order' => $s->display_order,
            'created_at' => $s->created_at?->toIso8601String(), 'updated_at' => $s->updated_at?->toIso8601String(),
        ] : null;
    }

    public static function tag(Tag $t): array
    {
        return ['id' => $t->id, 'name' => $t->name, 'slug' => $t->slug, 'created_at' => $t->created_at?->toIso8601String()];
    }
}
