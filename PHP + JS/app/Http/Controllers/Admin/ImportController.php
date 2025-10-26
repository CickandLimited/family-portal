<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\PlanImportService;
use App\Services\Progress\ProgressService;
use App\Services\XP\XPService;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ImportController extends Controller
{
    public function __construct(
        ProgressService $progressService,
        XPService $xpService,
        ActivityLogger $activityLogger,
        private readonly PlanImportService $planImportService,
    ) {
        parent::__construct($progressService, $xpService, $activityLogger);
    }

    public function show(): ViewContract
    {
        $users = User::query()
            ->orderBy('display_name')
            ->get();

        return view('admin.import', [
            'title' => 'Import Plan',
            'users' => $users,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'assignee_user_id' => ['required', 'integer', 'exists:user,id'],
            'file' => ['required', 'file'],
        ]);

        $uploadedFile = $request->file('file');
        if ($uploadedFile === null) {
            throw ValidationException::withMessages([
                'file' => 'A markdown file upload is required.',
            ]);
        }

        $contents = @file_get_contents($uploadedFile->getRealPath() ?: '');
        if ($contents === false) {
            return $this->logAndRespondFailure($validated, $uploadedFile->getClientOriginalName(), 'Unable to read the uploaded file.');
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            return $this->logAndRespondFailure($validated, $uploadedFile->getClientOriginalName(), 'Uploaded file must be valid UTF-8 text.');
        }

        $assigneeId = (int) $validated['assignee_user_id'];
        $creatorId = $this->resolveUserId($request);

        try {
            $plan = DB::transaction(function () use ($contents, $assigneeId, $creatorId) {
                return $this->planImportService->importFromMarkdown($contents, $assigneeId, $creatorId);
            });
        } catch (InvalidArgumentException $exception) {
            return $this->logAndRespondFailure($validated, $uploadedFile->getClientOriginalName(), $exception->getMessage());
        }

        $metadata = [
            'filename' => $uploadedFile->getClientOriginalName(),
            'assignee_user_id' => $assigneeId,
        ];

        $this->activityLogger()->log(
            action: 'plan.imported',
            entityType: 'plan',
            entityId: (int) $plan->getKey(),
            metadata: $metadata,
        );

        return response()->json([
            'plan_id' => $plan->getKey(),
        ]);
    }

    /**
     * @param array<string, mixed> $validated
     */
    private function logAndRespondFailure(array $validated, ?string $filename, string $message): JsonResponse
    {
        $metadata = [
            'filename' => $filename,
            'assignee_user_id' => $validated['assignee_user_id'] ?? null,
            'error' => $message,
        ];

        $this->activityLogger()->log(
            action: 'plan.import_failed',
            entityType: 'plan',
            entityId: 0,
            metadata: $metadata,
        );

        return response()->json([
            'detail' => $message,
        ], 400);
    }

    private function resolveUserId(Request $request): ?int
    {
        $user = $request->user();
        if ($user !== null && method_exists($user, 'getAuthIdentifier')) {
            $identifier = $user->getAuthIdentifier();
            if (is_numeric($identifier)) {
                return (int) $identifier;
            }
        }

        return null;
    }
}
