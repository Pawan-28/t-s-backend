<?php

namespace App\Http\Controllers\Api\Workflow;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkflowResources;
use App\Models\PublishingSchedule;
use App\Support\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** GET /api/reporters/schedules/ - admin, read-only, newest first (cancel via the article's cancel-schedule action). */
class PublishingScheduleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = PublishingSchedule::query()->with(['article', 'scheduledBy'])->orderByDesc('created_at')->orderByDesc('id');

        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            if (! in_array($status, [PublishingSchedule::PENDING, PublishingSchedule::EXECUTED, PublishingSchedule::CANCELLED], true)) {
                throw ValidationException::withMessages(['status' => ["Select a valid choice. {$status} is not one of the available choices."]]);
            }
            $q->where('status', $status);
        }

        return response()->json(Page::make($q, $request, fn ($s) => WorkflowResources::schedule($s)));
    }
}
