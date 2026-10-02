<?php

namespace App\Http\Controllers\Api;

use App\Enums\AdPlacement;
use App\Http\Controllers\Api\Concerns\ParsesDrfInput;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdvertisementResource;
use App\Models\Advertisement;
use App\Services\Media\AdvertisementService;
use App\Services\Media\BunnyStorageException;
use App\Services\Media\ImageValidationException;
use App\Support\DateRange;
use App\Support\Page;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * /api/advertisements/ (Django AdvertisementViewSet). `active/` is public and
 * returns a plain array of currently-live ads; everything else is ADMIN-only
 * (route middleware) and accepts multipart with `creative_upload`.
 */
class AdvertisementController extends Controller
{
    use ParsesDrfInput;

    private const DATETIME_RE = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?\s*(Z|[+-]\d{2}:?\d{2})?$/i';

    public function __construct(private AdvertisementService $ads) {}

    public function active(Request $request): JsonResponse
    {
        $now = now();
        $q = Advertisement::query()->where('is_active', true)->where('start_at', '<=', $now)->where('end_at', '>=', $now);
        $placement = $request->query('placement');
        if (is_string($placement) && $placement !== '') {
            $q->where('placement', $placement);
        }
        $rows = $q->orderByDesc('priority')->orderByDesc('created_at')->orderByDesc('id')->get();

        return response()->json($rows->map(fn (Advertisement $a) => AdvertisementResource::publicArray($a))->all());
    }

    public function index(Request $request): JsonResponse
    {
        $q = Advertisement::query();
        $errors = [];
        $placement = $request->query('placement');
        if (is_string($placement) && $placement !== '') {
            if (AdPlacement::tryFrom($placement) === null) {
                $errors['placement'] = ["Select a valid choice. {$placement} is not one of the available choices."];
            } else {
                $q->where('placement', $placement);
            }
        }
        $ct = $request->query('creative_type');
        if (is_string($ct) && $ct !== '') {
            if ($ct !== 'IMAGE') {
                $errors['creative_type'] = ["Select a valid choice. {$ct} is not one of the available choices."];
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        if (($b = $this->drfBool($request->query('is_active'))) !== null) {
            $q->where('is_active', $b);
        }
        $q->orderByDesc('priority')->orderByDesc('created_at')->orderByDesc('id');

        return response()->json(Page::make($q, $request, fn (Advertisement $a) => (new AdvertisementResource($a))->resolve()));
    }

    public function show(int $id): JsonResponse
    {
        return response()->json((new AdvertisementResource(Advertisement::query()->findOrFail($id)))->resolve());
    }

    public function store(Request $request): JsonResponse
    {
        [$attrs, $errors] = $this->fields($request, null, false);
        $file = $this->creative($request, $errors);
        if (! $errors && ! $file) {
            $errors['creative_upload'] = ['A creative image upload is required.'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $attrs += ['is_active' => true, 'priority' => 0, 'target_url' => ''];

        try {
            $ad = $this->ads->create($attrs, $file);
        } catch (ImageValidationException $e) {
            throw ValidationException::withMessages(['creative_upload' => [$e->getMessage()]]);
        } catch (BunnyStorageException) {
            throw $this->storageDown();
        }

        return response()->json((new AdvertisementResource($ad))->resolve(), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $ad = Advertisement::query()->findOrFail($id);
        $partial = $request->isMethod('PATCH');
        [$attrs, $errors] = $this->fields($request, $ad, ! $partial);
        $file = $this->creative($request, $errors);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        try {
            $ad = $this->ads->update($ad, $attrs, $file);
        } catch (ImageValidationException $e) {
            throw ValidationException::withMessages(['creative_upload' => [$e->getMessage()]]);
        } catch (BunnyStorageException) {
            throw $this->storageDown();
        }

        return response()->json((new AdvertisementResource($ad))->resolve());
    }

    public function destroy(int $id): JsonResponse
    {
        $this->ads->delete(Advertisement::query()->findOrFail($id));

        return response()->json(null, 204);
    }

    // ------------------------------------------------------------------ helpers

    private function storageDown(): HttpException
    {
        Log::error('Advertisement creative upload failed at Bunny');

        return new HttpException(502, 'Image storage is temporarily unavailable. Please try again.');
    }

    /**
     * DRF-style validation of the writable fields.
     *
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>}
     */
    private function fields(Request $request, ?Advertisement $current, bool $requireAll): array
    {
        $attrs = [];
        $errors = [];
        $missing = fn (string $f): bool => ! $request->has($f);
        $required = 'This field is required.';

        // name
        if ($missing('name')) {
            if ($requireAll || $current === null) {
                $errors['name'] = [$required];
            }
        } else {
            [$v, $e] = $this->drfChar($request->input('name'), 150, false);
            $e ? $errors['name'] = [$e] : $attrs['name'] = $v;
        }

        // placement
        if ($missing('placement')) {
            if ($requireAll || $current === null) {
                $errors['placement'] = [$required];
            }
        } else {
            $raw = $request->input('placement');
            if ($raw === null) {
                $errors['placement'] = ['This field may not be null.'];
            } elseif (! is_string($raw) || AdPlacement::tryFrom($raw) === null) {
                $errors['placement'] = ['"'.(is_scalar($raw) ? $raw : 'value').'" is not a valid choice.'];
            } else {
                $attrs['placement'] = AdPlacement::from($raw);
            }
        }

        // creative_type (single choice: IMAGE; no column)
        $ct = $request->input('creative_type');
        if ($request->has('creative_type') && $ct !== null && $ct !== 'IMAGE') {
            $errors['creative_type'] = ['"'.(is_scalar($ct) ? $ct : 'value').'" is not a valid choice.'];
        }

        // target_url: OPTIONAL (blank allowed)
        if ($request->has('target_url')) {
            $raw = $request->input('target_url');
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                $attrs['target_url'] = '';
            } else {
                $errs = $this->urlErrors($raw);
                $errs ? $errors['target_url'] = $errs : $attrs['target_url'] = trim((string) $raw);
            }
        }

        // start_at / end_at
        foreach (['start_at', 'end_at'] as $f) {
            if ($missing($f)) {
                if ($requireAll || $current === null) {
                    $errors[$f] = [$required];
                }

                continue;
            }
            [$v, $e] = $this->parseDate($request->input($f));
            $e ? $errors[$f] = [$e] : $attrs[$f] = $v;
        }

        // is_active
        if ($request->has('is_active') && $request->input('is_active') !== null) {
            $raw = $request->input('is_active');
            $b = $this->drfBool($raw);
            $b === null ? $errors['is_active'] = ['"'.(is_scalar($raw) ? $raw : 'value').'" is not a valid boolean.'] : $attrs['is_active'] = $b;
        }

        // priority
        if ($request->has('priority') && $request->input('priority') !== null) {
            [$v, $e] = $this->drfPositiveInt($request->input('priority'));
            $e ? $errors['priority'] = [$e] : $attrs['priority'] = $v;
        }

        // cross-field rule (only once the individual fields are valid)
        if (! $errors) {
            $s = $attrs['start_at'] ?? $current?->start_at;
            $e = $attrs['end_at'] ?? $current?->end_at;
            if ($s && $e && $s->greaterThanOrEqualTo($e)) {
                $errors['end_at'] = ['End date must be after the start date.'];
            }
        }

        return [$attrs, $errors];
    }

    /** @return list<string> */
    private function urlErrors(mixed $raw): array
    {
        if (! is_string($raw)) {
            return ['Not a valid string.'];
        }
        $url = trim($raw);
        $errs = [];
        $p = parse_url($url);
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        $validUrl = $p !== false && in_array($scheme, ['http', 'https', 'ftp', 'ftps'], true)
            && ! empty($p['host']) && ! preg_match('/\s/', $url)
            && preg_match('/^[A-Za-z0-9.\-\[\]:]+$/', (string) $p['host']) === 1;
        if (! $validUrl) {
            $errs[] = 'Enter a valid URL.';
        }
        if (mb_strlen($url) > 500) {
            $errs[] = 'Ensure this field has no more than 500 characters.';
        }
        if (! in_array($scheme, ['http', 'https'], true)) {
            $errs[] = "Unsafe or unsupported URL scheme '".($scheme !== '' ? $scheme : '(none)')."'. Only http:// and https:// are allowed.";
        }

        return $errs;
    }

    /** @return array{0: ?Carbon, 1: ?string} */
    private function parseDate(mixed $raw): array
    {
        $fmt = 'Datetime has wrong format. Use one of these formats instead: YYYY-MM-DDThh:mm[:ss[.uuuuuu]][+HH:MM|-HH:MM|Z].';
        if ($raw === null) {
            return [null, 'This field may not be null.'];
        }
        if (! is_string($raw) || ! preg_match(self::DATETIME_RE, trim($raw))) {
            return [null, $fmt];
        }
        try {
            $d = Carbon::parse(trim($raw))->setTimezone(config('app.timezone'));

            return DateRange::fits($d) ? [$d, null] : [null, DateRange::MESSAGE];
        } catch (\Throwable) {
            return [null, 'Datetime has wrong format. Use one of these formats instead: YYYY-MM-DDThh:mm[:ss[.uuuuuu]][+HH:MM|-HH:MM|Z].'];
        }
    }

    /** `creative_upload`: optional; blank/null means "no new file". */
    private function creative(Request $request, array &$errors): ?UploadedFile
    {
        $file = $request->file('creative_upload');
        if ($file instanceof UploadedFile) {
            return $file;
        }
        $raw = $request->input('creative_upload');
        if ($raw !== null && $raw !== '') {
            $errors['creative_upload'] = ['The submitted data was not a file. Check the encoding type on the form.'];
        }

        return null;
    }
}
