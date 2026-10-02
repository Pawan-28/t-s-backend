<?php

namespace App\Http\Resources;

use App\Models\Advertisement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin shape (Django AdvertisementSerializer) or, with public(), the minimal
 * PublicAdvertisementSerializer shape. `creative_type` is always "IMAGE" (the
 * only Django choice; it has no DB column here).
 *
 * @mixin Advertisement
 */
class AdvertisementResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var Advertisement $a */
        $a = $this->resource;

        return [
            'id' => $a->id,
            'name' => $a->name,
            'placement' => $a->placement->value,
            'creative_type' => 'IMAGE',
            'image_url' => $a->image_url,
            'target_url' => (string) $a->target_url,
            'start_at' => $a->start_at?->toIso8601String(),
            'end_at' => $a->end_at?->toIso8601String(),
            'is_active' => (bool) $a->is_active,
            'priority' => (int) $a->priority,
            'created_at' => $a->created_at?->toIso8601String(),
            'updated_at' => $a->updated_at?->toIso8601String(),
        ];
    }

    /** Public shape: never exposes schedule, flags or storage bookkeeping. */
    public static function publicArray(Advertisement $a): array
    {
        return [
            'id' => $a->id,
            'name' => $a->name,
            'placement' => $a->placement->value,
            'image_url' => $a->image_url,
            'target_url' => (string) $a->target_url,
            'priority' => (int) $a->priority,
        ];
    }
}
