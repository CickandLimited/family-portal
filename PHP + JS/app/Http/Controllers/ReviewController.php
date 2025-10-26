<?php

namespace App\Http\Controllers;

use App\Enums\ApprovalAction;
use App\Enums\ApprovalMood;
use App\Enums\SubtaskStatus;
use App\Http\Middleware\EnsureDeviceCookie;
use App\Models\Approval;
use App\Models\Device;
use App\Models\Plan;
use App\Models\PlanDay;
use App\Models\Subtask;
use App\Models\SubtaskSubmission;
use App\Models\User;
use App\Models\XPEvent;
use App\Services\ActivityLogger;
use App\Services\Progress\ProgressCache;
use App\Services\Progress\ProgressService;
use App\Services\XP\XPService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ReviewController extends Controller
{
    public function __construct(
        ProgressService $progressService,
        XPService $xpService,
        ActivityLogger $activityLogger,
    ) {
        parent::__construct($progressService, $xpService, $activityLogger);
    }

    public function queue(Request $request): Response
    {
        [$actingDevice, $actingUser] = $this->resolveActors($request);

        $items = $this->queueItems($actingUser, $actingDevice);

        return response()->view('review', [
            'title' => 'Review Queue',
            'items' => $items,
            'moodOptions' => $this->moodOptions(),
            'defaultMood' => $this->defaultMood(),
            'device' => $this->deviceContext($actingDevice),
        ]);
    }

    public function queuePartial(Request $request): ViewContract
    {
        [$actingDevice, $actingUser] = $this->resolveActors($request);

        $items = $this->queueItems($actingUser, $actingDevice);

        return view('components.review-queue-items', [
            'items' => $items,
            'moodOptions' => $this->moodOptions(),
            'defaultMood' => $this->defaultMood(),
            'device' => $this->deviceContext($actingDevice),
        ]);
    }

    public function approve(Request $request, int $subtaskId): Response|RedirectResponse
    {
        [$actingDevice, $actingUser] = $this->resolveActors($request);

        if (! $actingDevice instanceof Device) {
            abort(400, 'Device context is required for approvals.');
        }

        $mood = $this->resolveMood($request->input('mood'));
        $submissionId = $this->resolveSubmissionId($request->input('submission_id'));
        $notes = trim((string) $request->input('notes', ''));

        $subtask = $this->reviewQuery()->find($subtaskId);
        if (! $subtask instanceof Subtask) {
            abort(404, 'Subtask not found.');
        }

        if ($subtask->status !== SubtaskStatus::SUBMITTED) {
            abort(400, 'Subtask is not awaiting review.');
        }

        [$allowed, $message] = $this->canApprove($subtask, $actingUser, $actingDevice);
        if (! $allowed) {
            abort(403, $message ?? 'Approval is currently blocked.');
        }

        $submission = $this->requireSubmission($subtask, $submissionId);

        $planDay = $subtask->planDay;
        $plan = $planDay?->plan;
        if (! $plan instanceof Plan) {
            abort(400, 'Associated plan could not be loaded.');
        }

        $assigneeId = $plan->assignee_user_id;
        $dayWasComplete = $planDay instanceof PlanDay ? $this->xp()->isDayComplete($planDay) : false;
        $planWasComplete = $this->xp()->isPlanComplete($plan);

        $now = Carbon::now();
        $xpEvents = [];

        DB::transaction(function () use (
            &$xpEvents,
            $subtask,
            $planDay,
            $plan,
            $actingDevice,
            $actingUser,
            $mood,
            $notes,
            $now,
            $assigneeId,
            $dayWasComplete,
            $planWasComplete,
            $submission,
        ): void {
            $subtask->status = SubtaskStatus::APPROVED;
            $subtask->updated_at = $now;
            $subtask->save();

            if ($planDay instanceof PlanDay) {
                $planDay->updated_at = $now;
                $planDay->save();
            }

            $plan->updated_at = $now;
            $plan->save();

            Approval::create([
                'subtask_id' => $subtask->getKey(),
                'action' => ApprovalAction::APPROVE,
                'mood' => $mood,
                'reason' => $notes !== '' ? $notes : null,
                'acted_by_device_id' => $actingDevice->getKey(),
                'acted_by_user_id' => $actingUser?->getKey(),
            ]);

            if ($planDay instanceof PlanDay) {
                $planDay->load('subtasks');
            }

            $plan->load('days.subtasks');

            if ($assigneeId !== null) {
                $xpEvents[] = XPEvent::create([
                    'user_id' => $assigneeId,
                    'subtask_id' => $subtask->getKey(),
                    'delta' => (int) $subtask->xp_value,
                    'reason' => XPService::XP_APPROVAL_REASON,
                ]);
            }

            $dayIsComplete = $planDay instanceof PlanDay ? $this->xp()->isDayComplete($planDay) : false;
            if (
                $planDay instanceof PlanDay
                && $assigneeId !== null
                && ! $dayWasComplete
                && $dayIsComplete
                && $this->xp()->isDayBonusEligible($planDay)
            ) {
                $xpEvents[] = XPEvent::create([
                    'user_id' => $assigneeId,
                    'subtask_id' => null,
                    'delta' => XPService::DAY_COMPLETION_BONUS,
                    'reason' => XPService::XP_DAY_COMPLETION_REASON,
                ]);
            }

            $planLockChanged = $this->progress()->refreshPlanDayLocks($plan);
            if ($planLockChanged) {
                $plan->push();
            }

            $planIsComplete = $this->xp()->isPlanComplete($plan);
            $planHasDays = $plan->relationLoaded('days')
                ? $plan->getRelation('days')->isNotEmpty()
                : $plan->days()->exists();

            if (
                $assigneeId !== null
                && ! $planWasComplete
                && $planIsComplete
                && $planHasDays
            ) {
                $xpEvents[] = XPEvent::create([
                    'user_id' => $assigneeId,
                    'subtask_id' => null,
                    'delta' => XPService::PLAN_COMPLETION_BONUS,
                    'reason' => XPService::XP_PLAN_COMPLETION_REASON,
                ]);
            }

            $plan->total_xp = $this->xp()->calculatePlanTotalXp($plan);
            $plan->save();

            $this->activityLogger()->log(
                'subtask.approved',
                'subtask',
                (int) $subtask->getKey(),
                [
                    'plan_id' => $plan->getKey(),
                    'plan_title' => $plan->title,
                    'plan_day_id' => $planDay?->getKey(),
                    'mood' => $mood->value,
                    'xp_value' => (int) $subtask->xp_value,
                    'approval_notes' => $notes !== '' ? $notes : null,
                    'submission_id' => $submission->getKey(),
                    'xp_events' => array_map(
                        static fn (XPEvent $event): array => [
                            'reason' => $event->reason,
                            'delta' => (int) $event->delta,
                        ],
                        $xpEvents
                    ),
                ],
                device: $actingDevice,
                user: $actingUser,
            );
        });

        if ($this->isHtmx($request)) {
            $payload = [
                'reviewQueueRefresh' => true,
                'planProgressUpdated' => [
                    'plan_id' => $plan->getKey(),
                    'day_id' => $planDay?->getKey(),
                ],
            ];

            $response = response()->noContent();
            $response->headers->set('HX-Trigger', $this->encodeTrigger($payload));

            return $response;
        }

        return redirect()->route('review.queue')->setStatusCode(303);
    }

    public function deny(Request $request, int $subtaskId): Response|RedirectResponse
    {
        [$actingDevice, $actingUser] = $this->resolveActors($request);

        if (! $actingDevice instanceof Device) {
            abort(400, 'Device context is required for approvals.');
        }

        $mood = $this->resolveMood($request->input('mood'));
        $submissionId = $this->resolveSubmissionId($request->input('submission_id'));
        $reason = trim((string) $request->input('reason', ''));

        if ($reason === '') {
            abort(400, 'A reason is required when denying submissions.');
        }

        $subtask = $this->reviewQuery()->find($subtaskId);
        if (! $subtask instanceof Subtask) {
            abort(404, 'Subtask not found.');
        }

        if ($subtask->status !== SubtaskStatus::SUBMITTED) {
            abort(400, 'Subtask is not awaiting review.');
        }

        [$allowed, $message] = $this->canApprove($subtask, $actingUser, $actingDevice);
        if (! $allowed) {
            abort(403, $message ?? 'Approval is currently blocked.');
        }

        $submission = $this->requireSubmission($subtask, $submissionId);

        $planDay = $subtask->planDay;
        $plan = $planDay?->plan;
        if (! $plan instanceof Plan) {
            abort(400, 'Associated plan could not be loaded.');
        }

        $now = Carbon::now();

        DB::transaction(function () use (
            $subtask,
            $planDay,
            $plan,
            $actingDevice,
            $actingUser,
            $mood,
            $reason,
            $now,
            $submission,
        ): void {
            $subtask->status = SubtaskStatus::DENIED;
            $subtask->updated_at = $now;
            $subtask->save();

            if ($planDay instanceof PlanDay) {
                $planDay->updated_at = $now;
                $planDay->save();
            }

            $plan->updated_at = $now;
            $plan->save();

            Approval::create([
                'subtask_id' => $subtask->getKey(),
                'action' => ApprovalAction::DENY,
                'mood' => $mood,
                'reason' => $reason,
                'acted_by_device_id' => $actingDevice->getKey(),
                'acted_by_user_id' => $actingUser?->getKey(),
            ]);

            $planLockChanged = $this->progress()->refreshPlanDayLocks($plan);
            if ($planLockChanged) {
                $plan->push();
            }

            $plan->total_xp = $this->xp()->calculatePlanTotalXp($plan);
            $plan->save();

            $this->activityLogger()->log(
                'subtask.denied',
                'subtask',
                (int) $subtask->getKey(),
                [
                    'plan_id' => $plan->getKey(),
                    'plan_title' => $plan->title,
                    'plan_day_id' => $planDay?->getKey(),
                    'mood' => $mood->value,
                    'reason' => $reason,
                    'submission_id' => $submission->getKey(),
                ],
                device: $actingDevice,
                user: $actingUser,
            );
        });

        if ($this->isHtmx($request)) {
            $payload = [
                'reviewQueueRefresh' => true,
                'planProgressUpdated' => [
                    'plan_id' => $plan->getKey(),
                    'day_id' => $planDay?->getKey(),
                ],
            ];

            $response = response()->noContent();
            $response->headers->set('HX-Trigger', $this->encodeTrigger($payload));

            return $response;
        }

        return redirect()->route('review.queue')->setStatusCode(303);
    }

    private function reviewQuery(): Builder
    {
        return Subtask::query()->with([
            'planDay.plan.assignee',
            'planDay.plan.days.subtasks',
            'planDay.subtasks',
            'submissions.submittedByUser',
            'submissions.submittedByDevice.linkedUser',
        ]);
    }

    private function queueItems(?User $actingUser, ?Device $actingDevice): array
    {
        $subtasks = $this->reviewQuery()
            ->where('status', SubtaskStatus::SUBMITTED)
            ->orderByDesc('updated_at')
            ->get();

        $items = [];
        $cache = new ProgressCache();

        foreach ($subtasks as $subtask) {
            $item = $this->buildQueueItem($subtask, $actingUser, $actingDevice, $cache);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function buildQueueItem(
        Subtask $subtask,
        ?User $actingUser,
        ?Device $actingDevice,
        ProgressCache $cache
    ): ?array {
        $submission = $this->latestSubmission($subtask);
        if (! $submission instanceof SubtaskSubmission) {
            return null;
        }

        $planDay = $subtask->planDay;
        $plan = $planDay?->plan;

        if (! $planDay instanceof PlanDay || ! $plan instanceof Plan) {
            return null;
        }

        [$allowed, $message] = $this->canApprove($subtask, $actingUser, $actingDevice);
        $planProgress = $this->progress()->calculatePlanProgress($plan, $cache);
        $dayProgress = $this->progress()->calculateDayProgress($planDay, $cache);

        return [
            'subtask_id' => $subtask->getKey(),
            'subtask_text' => $subtask->text,
            'xp_value' => (int) $subtask->xp_value,
            'plan_id' => $plan->getKey(),
            'plan_title' => $plan->title,
            'assignee_name' => $plan->assignee?->display_name,
            'day_number' => (int) $planDay->day_index + 1,
            'day_title' => $planDay->title,
            'latest_submission' => $this->submissionContext($submission),
            'approval_allowed' => $allowed,
            'approval_message' => $message,
            'plan_progress' => [
                'percent' => $planProgress->percentComplete(),
                'approved_subtasks' => $planProgress->approvedSubtasks,
                'total_subtasks' => $planProgress->totalSubtasks,
                'completed_days' => $planProgress->completedDays,
                'total_days' => $planProgress->totalDays,
                'day_percent' => $planProgress->dayPercentComplete(),
            ],
            'day_progress' => [
                'percent' => $dayProgress->percentComplete(),
                'approved_subtasks' => $dayProgress->approvedSubtasks,
                'total_subtasks' => $dayProgress->totalSubtasks,
            ],
        ];
    }

    private function latestSubmission(Subtask $subtask): ?SubtaskSubmission
    {
        if ($subtask->relationLoaded('submissions')) {
            return $subtask->submissions->sortByDesc('created_at')->first();
        }

        return $subtask->submissions()->latest('created_at')->first();
    }

    private function canApprove(Subtask $subtask, ?User $actingUser, ?Device $actingDevice): array
    {
        $plan = $subtask->planDay?->plan;
        $assigneeId = $plan?->assignee_user_id;

        if ($assigneeId === null) {
            return [true, null];
        }

        if ($actingUser instanceof User && (int) $actingUser->getKey() === (int) $assigneeId) {
            return [false, 'Assignees cannot approve their own submissions.'];
        }

        if (
            $actingDevice instanceof Device
            && $actingDevice->linked_user_id !== null
            && (int) $actingDevice->linked_user_id === (int) $assigneeId
        ) {
            return [false, 'Devices linked to the assignee cannot approve this submission.'];
        }

        return [true, null];
    }

    private function requireSubmission(Subtask $subtask, ?int $submissionId): SubtaskSubmission
    {
        $submission = $this->latestSubmission($subtask);
        if (! $submission instanceof SubtaskSubmission) {
            abort(400, 'No submission available for review.');
        }

        if ($submissionId !== null && $submission->getKey() !== $submissionId) {
            abort(409, 'The submission has changed. Refresh the queue and try again.');
        }

        return $submission;
    }

    private function resolveMood(mixed $value): ApprovalMood
    {
        $mood = is_scalar($value) ? ApprovalMood::tryFrom((string) $value) : null;
        if (! $mood instanceof ApprovalMood) {
            abort(400, 'Select a valid mood option.');
        }

        return $mood;
    }

    private function resolveSubmissionId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            abort(400, 'Submission reference is invalid.');
        }

        return (int) $value;
    }

    private function resolveActors(Request $request): array
    {
        $deviceAttr = $request->attributes->get('device');
        $actingDevice = null;
        if ($deviceAttr instanceof Device) {
            $actingDevice = Device::query()
                ->with('linkedUser')
                ->find($deviceAttr->getKey())
                ?: $deviceAttr;
        } else {
            $cookieId = $request->cookies->get(EnsureDeviceCookie::COOKIE_NAME);
            if (is_string($cookieId) && $cookieId !== '') {
                $actingDevice = Device::query()
                    ->with('linkedUser')
                    ->find($cookieId);
            }
        }

        $userAttr = $request->user();
        $actingUser = null;
        if ($userAttr instanceof User) {
            $actingUser = $userAttr;
        } elseif ($userAttr instanceof Authenticatable && method_exists($userAttr, 'getAuthIdentifier')) {
            $identifier = $userAttr->getAuthIdentifier();
            if ($identifier !== null) {
                $actingUser = User::query()->find($identifier);
            }
        }

        return [$actingDevice, $actingUser];
    }

    private function deviceContext(?Device $device): ?array
    {
        if (! $device instanceof Device) {
            return null;
        }

        return [
            'id' => $device->getKey(),
            'label' => $this->deviceLabel($device),
            'friendly_name' => $device->friendly_name,
            'linked_user_name' => $device->linkedUser?->display_name,
        ];
    }

    private function deviceLabel(Device $device): string
    {
        if ($device->friendly_name !== null && $device->friendly_name !== '') {
            return $device->friendly_name;
        }

        return 'Device ' . $device->getKey();
    }

    private function submissionContext(SubtaskSubmission $submission): array
    {
        $submittedBy = $submission->submittedByUser?->display_name
            ?? $submission->submittedByDevice?->friendly_name
            ?? 'Unknown submitter';

        $deviceLabel = null;
        $linkedUser = null;
        if ($submission->submittedByDevice instanceof Device) {
            $deviceLabel = $this->deviceLabel($submission->submittedByDevice);
            $linkedUser = $submission->submittedByDevice->linkedUser?->display_name;
        }

        $createdDisplay = null;
        if ($submission->created_at !== null) {
            $createdDisplay = $submission->created_at->copy()->format('M d, Y h:i A');
        }

        return [
            'id' => $submission->getKey(),
            'comment' => $submission->comment,
            'photo_path' => $submission->photo_path,
            'submitted_by' => $submittedBy,
            'submitted_at' => $submission->created_at,
            'submitted_display' => $createdDisplay,
            'device_label' => $deviceLabel,
            'device_linked_user' => $linkedUser,
        ];
    }

    private function encodeTrigger(array $payload): string
    {
        $encoded = json_encode($payload);

        return $encoded === false ? '{}' : $encoded;
    }

    private function moodOptions(): array
    {
        /** @var array<int, array<string, string>> $options */
        $options = config('ui.approval_moods', []);

        return $options;
    }

    private function defaultMood(): string
    {
        $default = config('ui.default_approval_mood');
        if (is_string($default)) {
            return $default;
        }

        return ApprovalMood::NEUTRAL->value;
    }
}
