<?php

namespace App\Http\Controllers;

use App\Enums\PlanStatus;
use App\Enums\SubtaskStatus;
use App\Models\Attachment;
use App\Models\Device;
use App\Models\Plan;
use App\Models\PlanDay;
use App\Models\Subtask;
use App\Models\SubtaskSubmission;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ImageProcessingException;
use App\Services\ImageProcessor;
use App\Services\Progress\ProgressService;
use App\Services\XP\XPService;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class PlanController extends Controller
{
    public function __construct(
        ProgressService $progressService,
        XPService $xpService,
        ActivityLogger $activityLogger,
        private readonly ImageProcessor $imageProcessor,
    ) {
        parent::__construct($progressService, $xpService, $activityLogger);
    }

    public function show(Request $request, int $planId): Response
    {
        $plan = $this->loadPlan($planId);

        return $this->renderPlan($request, $plan);
    }

    public function progressPartial(Request $request, int $planId): ViewContract
    {
        $plan = $this->loadPlan($planId);
        $planContext = $this->buildPlanContext($plan);

        return view('components.plan-progress-overview', [
            'plan' => $planContext,
        ]);
    }

    public function daysPartial(Request $request, int $planId): ViewContract
    {
        $plan = $this->loadPlan($planId);
        $planContext = $this->buildPlanContext($plan);

        return view('components.plan-day-list', [
            'plan' => $planContext,
        ]);
    }

    public function submit(Request $request, int $planId): JsonResponse|Response|RedirectResponse
    {
        $plan = $this->loadPlan($planId);
        $device = $request->attributes->get('device');

        $comment = trim((string) $request->input('comment', ''));
        $userIdRaw = $request->input('user_id');
        $subtaskIdRaw = $request->input('subtask_id');

        $formState = [
            'comment' => $comment,
            'user_id' => is_scalar($userIdRaw) ? (string) $userIdRaw : '',
            'subtask_id' => is_numeric($subtaskIdRaw) ? (int) $subtaskIdRaw : null,
        ];

        if (! $device instanceof Device) {
            return $this->submissionErrorResponse(
                $request,
                $plan,
                ["Device not recognized."],
                $formState,
                400
            );
        }

        if (! is_numeric($subtaskIdRaw)) {
            return $this->submissionErrorResponse(
                $request,
                $plan,
                ["We couldn't find that subtask."],
                $formState,
            );
        }

        $subtaskId = (int) $subtaskIdRaw;
        $formState['subtask_id'] = $subtaskId;

        $submittedUser = null;
        $userId = trim((string) $formState['user_id']);
        if ($userId !== '') {
            if (! ctype_digit($userId)) {
                return $this->submissionErrorResponse(
                    $request,
                    $plan,
                    ['Select a valid family member or leave the field blank.'],
                    $formState,
                );
            }

            /** @var User|null $candidate */
            $candidate = User::query()->where('id', (int) $userId)->where('is_active', true)->first();
            if ($candidate === null) {
                return $this->submissionErrorResponse(
                    $request,
                    $plan,
                    ['Select a valid family member or leave the field blank.'],
                    $formState,
                );
            }

            $submittedUser = $candidate;
        }

        /** @var Subtask|null $subtask */
        $subtask = Subtask::query()->with('planDay')->find($subtaskId);
        $errors = [];

        if ($subtask === null || $subtask->planDay?->plan_id !== $plan->getKey()) {
            $errors[] = "We couldn't find that subtask.";
        } elseif (! in_array($subtask->status, [SubtaskStatus::PENDING, SubtaskStatus::DENIED], true)) {
            $errors[] = "This subtask isn't accepting submissions right now.";
        }

        $photo = $request->file('photo');
        $maxUploadMb = (int) config('family.max_upload_mb', 6);
        $maxKilobytes = $maxUploadMb * 1024;

        $photoValidator = Validator::make(
            ['photo' => $photo],
            [
                'photo' => [
                    'required',
                    'file',
                    'uploaded',
                    'mimetypes:image/jpeg,image/png,image/webp',
                    'max:' . $maxKilobytes,
                ],
            ],
            [
                'photo.required' => 'Please attach a photo to submit evidence.',
                'photo.file' => 'Please attach a photo to submit evidence.',
                'photo.uploaded' => "We couldn't process that photo. Please try again.",
                'photo.mimetypes' => 'Please upload a JPEG, PNG, or WEBP image.',
                'photo.max' => sprintf('Photo is too large. Maximum allowed size is %d MB.', $maxUploadMb),
            ]
        );

        if ($photoValidator->fails()) {
            $errors = array_merge($errors, $photoValidator->errors()->all());
        } else {
            /** @var \Illuminate\Http\UploadedFile $photo */
            $photo = $photoValidator->validated()['photo'];
        }

        if ($errors !== []) {
            return $this->submissionErrorResponse($request, $plan, $errors, $formState);
        }

        try {
            $saved = $this->imageProcessor->process($photo);
        } catch (ImageProcessingException $exception) {
            return $this->submissionErrorResponse(
                $request,
                $plan,
                [$exception->getMessage()],
                $formState,
                $exception->statusCode
            );
        }

        $now = Carbon::now();
        $planDay = $subtask?->planDay;
        $submission = null;

        DB::transaction(function () use (
            &$submission,
            $plan,
            $subtask,
            $planDay,
            $device,
            $submittedUser,
            $comment,
            $saved,
            $now
        ) {
            if ($subtask === null) {
                return;
            }

            $subtask->status = SubtaskStatus::SUBMITTED;
            $subtask->updated_at = $now;
            $subtask->save();

            if ($planDay instanceof PlanDay) {
                $planDay->updated_at = $now;
                $planDay->save();
            }

            $plan->updated_at = $now;
            $plan->save();

            $submission = SubtaskSubmission::create([
                'subtask_id' => $subtask->getKey(),
                'submitted_by_device_id' => $device->getKey(),
                'submitted_by_user_id' => $submittedUser?->getKey(),
                'comment' => $comment !== '' ? $comment : null,
                'photo_path' => $saved['file'],
            ]);

            Attachment::create([
                'plan_id' => $plan->getKey(),
                'subtask_id' => $subtask->getKey(),
                'file_path' => $saved['file'],
                'thumb_path' => $saved['thumb'],
                'uploaded_by_device_id' => $device->getKey(),
                'uploaded_by_user_id' => $submittedUser?->getKey(),
            ]);

            $this->activityLogger()->log(
                'subtask.submitted',
                'submission',
                (int) $submission->getKey(),
                [
                    'plan_id' => $plan->getKey(),
                    'plan_title' => $plan->title,
                    'subtask_id' => $subtask->getKey(),
                    'subtask_title' => $subtask->text,
                    'comment' => $comment !== '' ? $comment : null,
                    'photo_path' => $saved['file'],
                    'submitted_user_id' => $submittedUser?->getKey(),
                    'submitted_user_name' => $submittedUser?->display_name,
                    'xp_value' => (int) $subtask->xp_value,
                ],
                device: $device,
                user: $submittedUser
            );
        });

        if ($this->isHtmx($request)) {
            $triggerPayload = [
                'planProgressUpdated' => [
                    'plan_id' => $plan->getKey(),
                    'day_id' => $planDay?->getKey(),
                ],
            ];

            $response = response()->noContent();
            $response->headers->set('HX-Trigger', json_encode($triggerPayload));

            return $response;
        }

        return redirect()
            ->route('plan.show', ['plan' => $plan->getKey()])
            ->setStatusCode(303);
    }

    private function renderPlan(Request $request, Plan $plan, array $overrides = [], int $status = 200): Response
    {
        $planContext = $this->buildPlanContext($plan);

        $data = array_merge([
            'title' => $planContext['title'] . ' • Plan',
            'plan' => $planContext,
            'identity_options' => $this->submissionIdentityOptions(),
            'max_upload_mb' => (int) config('family.max_upload_mb', 6),
            'active_modal' => null,
            'active_subtask_id' => null,
            'submission_errors' => [],
            'submission_form' => [
                'comment' => '',
                'user_id' => '',
                'subtask_id' => null,
            ],
        ], $overrides);

        return response()->view('plan', $data, $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPlanContext(Plan $plan): array
    {
        $plan->loadMissing([
            'assignee',
            'attachments.uploadedByUser',
            'attachments.uploadedByDevice',
            'days.subtasks.submissions.submittedByUser',
            'days.subtasks.submissions.submittedByDevice',
            'days.subtasks.attachments.uploadedByUser',
            'days.subtasks.attachments.uploadedByDevice',
        ]);

        $planAttachments = $plan->attachments
            ->sortByDesc('created_at')
            ->map(fn (Attachment $attachment) => $this->attachmentContext($attachment))
            ->values()
            ->all();

        $dayContexts = [];

        foreach ($plan->days->sortBy('day_index') as $day) {
            $subtaskContexts = [];

            foreach ($day->subtasks->sortBy('order_index') as $subtask) {
                $statusValue = $subtask->status->value;
                $statusLabel = Str::title(str_replace('_', ' ', $statusValue));
                $submissions = $subtask->submissions
                    ->sortByDesc('created_at')
                    ->map(fn (SubtaskSubmission $submission) => $this->submissionContext($submission))
                    ->values()
                    ->all();

                $attachments = $subtask->attachments
                    ->sortByDesc('created_at')
                    ->map(fn (Attachment $attachment) => $this->attachmentContext($attachment))
                    ->values()
                    ->all();

                $subtaskContexts[] = [
                    'id' => $subtask->getKey(),
                    'text' => $subtask->text,
                    'xp_value' => (int) $subtask->xp_value,
                    'status' => $statusValue,
                    'status_label' => $statusLabel,
                    'status_badge_class' => $this->subtaskStatusBadge($statusValue),
                    'submissions' => $submissions,
                    'attachments' => $attachments,
                    'can_submit' => in_array($subtask->status, [SubtaskStatus::PENDING, SubtaskStatus::DENIED], true),
                    'can_review' => $subtask->status === SubtaskStatus::SUBMITTED,
                ];
            }

            $dayProgress = $this->progress()->calculateDayProgress($day);

            $dayContexts[] = [
                'id' => $day->getKey(),
                'index' => (int) $day->day_index,
                'title' => $day->title,
                'locked' => (bool) $day->locked,
                'complete' => $dayProgress->isComplete(),
                'progress_percent' => $dayProgress->percentComplete(),
                'completed_subtasks' => $dayProgress->approvedSubtasks,
                'total_subtasks' => $dayProgress->totalSubtasks,
                'progress' => [
                    'percent' => $dayProgress->percentComplete(),
                    'approved_subtasks' => $dayProgress->approvedSubtasks,
                    'total_subtasks' => $dayProgress->totalSubtasks,
                ],
                'subtasks' => $subtaskContexts,
            ];
        }

        $planProgress = $this->progress()->calculatePlanProgress($plan);

        $assignee = null;
        if ($plan->assignee instanceof User) {
            $assignee = [
                'id' => $plan->assignee->getKey(),
                'display_name' => $plan->assignee->display_name,
                'avatar' => $plan->assignee->avatar,
            ];
        }

        $statusValue = $plan->status->value;

        return [
            'id' => $plan->getKey(),
            'title' => $plan->title,
            'status' => $statusValue,
            'status_label' => Str::title(str_replace('_', ' ', $statusValue)),
            'status_badge_class' => $this->planStatusBadge($statusValue),
            'total_xp' => (int) $plan->total_xp,
            'assignee' => $assignee,
            'attachments' => $planAttachments,
            'days' => $dayContexts,
            'completed_days' => $planProgress->completedDays,
            'total_days' => $planProgress->totalDays,
            'completed_subtasks' => $planProgress->approvedSubtasks,
            'total_subtasks' => $planProgress->totalSubtasks,
            'progress_percent' => $planProgress->percentComplete(),
            'progress' => [
                'percent' => $planProgress->percentComplete(),
                'approved_subtasks' => $planProgress->approvedSubtasks,
                'total_subtasks' => $planProgress->totalSubtasks,
                'completed_days' => $planProgress->completedDays,
                'total_days' => $planProgress->totalDays,
                'day_percent' => $planProgress->dayPercentComplete(),
            ],
            'updated_at' => $plan->updated_at,
        ];
    }

    private function loadPlan(int $planId): Plan
    {
        $query = Plan::query()->with([
            'assignee',
            'attachments.uploadedByUser',
            'attachments.uploadedByDevice',
            'days.subtasks.submissions.submittedByUser',
            'days.subtasks.submissions.submittedByDevice',
            'days.subtasks.attachments.uploadedByUser',
            'days.subtasks.attachments.uploadedByDevice',
        ]);

        /** @var Plan $plan */
        $plan = $query->findOrFail($planId);

        if ($this->progress()->refreshPlanDayLocks($plan)) {
            $plan->push();
            /** @var Plan $plan */
            $plan = $query->findOrFail($planId);
        }

        return $plan;
    }

    /**
     * @return array<int, array{id: int, display_name: string}>
     */
    private function submissionIdentityOptions(): array
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('display_name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->getKey(),
                'display_name' => $user->display_name,
            ])
            ->values()
            ->all();
    }

    private function attachmentContext(Attachment $attachment): array
    {
        $uploadedBy = null;
        if ($attachment->uploadedByUser instanceof User) {
            $uploadedBy = $attachment->uploadedByUser->display_name;
        } elseif ($attachment->uploadedByDevice instanceof Device) {
            $uploadedBy = $attachment->uploadedByDevice->friendly_name
                ?: 'Device ' . $attachment->uploadedByDevice->getKey();
        }

        $createdDisplay = null;
        if ($attachment->created_at instanceof Carbon) {
            $createdDisplay = $attachment->created_at->copy()->utc()->format('M d, Y h:i A');
        }

        return [
            'id' => $attachment->getKey(),
            'file_name' => basename((string) $attachment->file_path),
            'file_path' => $attachment->file_path,
            'thumb_path' => $attachment->thumb_path,
            'uploaded_by' => $uploadedBy,
            'created_at' => $attachment->created_at,
            'created_display' => $createdDisplay,
        ];
    }

    private function submissionContext(SubtaskSubmission $submission): array
    {
        $actor = null;
        $device = null;

        if ($submission->submittedByUser instanceof User) {
            $actor = $submission->submittedByUser->display_name;
        }

        if ($submission->submittedByDevice instanceof Device) {
            $device = $submission->submittedByDevice->friendly_name
                ?: 'Device ' . $submission->submittedByDevice->getKey();
        }

        if ($actor !== null && $device !== null) {
            $submittedBy = sprintf('%s via %s', $actor, $device);
        } elseif ($actor !== null) {
            $submittedBy = $actor;
        } elseif ($device !== null) {
            $submittedBy = $device;
        } else {
            $submittedBy = 'Unknown submitter';
        }

        $createdDisplay = null;
        if ($submission->created_at instanceof Carbon) {
            $createdDisplay = $submission->created_at->copy()->utc()->format('M d, Y h:i A');
        }

        return [
            'id' => $submission->getKey(),
            'submitted_by' => $submittedBy,
            'created_at' => $submission->created_at,
            'created_display' => $createdDisplay,
            'comment' => $submission->comment,
            'photo_path' => $submission->photo_path,
        ];
    }

    private function submissionErrorResponse(
        Request $request,
        Plan $plan,
        array $errors,
        array $formState,
        int $status = 422
    ): JsonResponse|Response {
        if ($this->isHtmx($request)) {
            return response()->json([
                'errors' => $errors,
                'form' => $formState,
            ], $status);
        }

        return $this->renderPlan(
            $request,
            $plan,
            [
                'active_modal' => 'submit',
                'active_subtask_id' => $formState['subtask_id'] ?? null,
                'submission_errors' => $errors,
                'submission_form' => $formState,
            ],
            $status
        );
    }

    private function planStatusBadge(string $status): string
    {
        /** @var array<string, string> $badges */
        $badges = config('ui.status_badges.plan', []);

        if (isset($badges[$status])) {
            return $badges[$status];
        }

        return $badges['draft'] ?? 'bg-slate-200 text-slate-700';
    }

    private function subtaskStatusBadge(string $status): string
    {
        /** @var array<string, string> $badges */
        $badges = config('ui.status_badges.subtask', []);

        if (isset($badges[$status])) {
            return $badges[$status];
        }

        return $badges['pending'] ?? 'bg-slate-200 text-slate-700';
    }
}
