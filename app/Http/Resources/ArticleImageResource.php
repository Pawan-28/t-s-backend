<?php

namespace App\Http\Resources;

use App\Models\ArticleImage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Django ArticleImageSerializer read shape. `metadata` is the nested
 * MediaMetadata object (only the six documented keys are ever exposed).
 * Eager-load `uploader`.
 *
 * @mixin ArticleImage
 */
class ArticleImageResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var ArticleImage $i */
        $i = $this->resource;
        $m = $i->metadata ?? [];

        return [
            'id' => $i->id,
            'bunny_url' => $i->bunny_url,
            'alt_text' => $i->alt_text,
            'caption' => $i->caption,
            'is_featured' => (bool) $i->is_featured,
            'display_order' => (int) $i->display_order,
            // The uploader's e-mail is newsroom-only; public callers get it masked.
            'uploaded_by' => ['id' => $i->uploaded_by_id, 'email' => self::uploaderEmail($request, $i->uploader?->email)],
            'metadata' => [
                'original_filename' => $m['original_filename'] ?? '',
                'content_type' => $m['content_type'] ?? '',
                'file_size_bytes' => (int) ($m['file_size_bytes'] ?? 0),
                'width' => (int) ($m['width'] ?? 0),
                'height' => (int) ($m['height'] ?? 0),
                'checksum' => $m['checksum'] ?? '',
            ],
            'created_at' => $i->created_at?->toIso8601String(),
            'updated_at' => $i->updated_at?->toIso8601String(),
        ];
    }

    private static function uploaderEmail(Request $request, ?string $email): ?string
    {
        if ($email === null) {
            return null;
        }
        // Works for public reads (bearer header) and authenticated writes alike; a refresh token never counts.
        $user = $request->user();
        $token = $user?->currentAccessToken();
        if (! $user instanceof User || ! $user->is_active || ! $token || ! method_exists($token, 'can') || ! $token->can('access')) {
            $user = null;
        }

        return $user && $user->isArticleStaff() ? $email : ArticleResource::maskEmail($email);
    }
}
