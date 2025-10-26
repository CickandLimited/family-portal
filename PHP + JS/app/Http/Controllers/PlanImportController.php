<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogger;
use App\Services\PlanImportService;
use App\Services\Progress\ProgressService;
use App\Services\XP\XPService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PlanImportController extends Controller
{
    public function __construct(
        ProgressService $progressService,
        XPService $xpService,
        ActivityLogger $activityLogger,
        private readonly PlanImportService $planImportService,
    ) {
        parent::__construct($progressService, $xpService, $activityLogger);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'assignee_user_id' => ['required', 'integer', 'exists:user,id'],
            'file' => ['required', 'file'],
        ]);

        /** @var UploadedFile $uploadedFile */
        $uploadedFile = $request->file('file');

        $metadata = [
            'filename' => $uploadedFile?->getClientOriginalName(),
            'assignee_user_id' => $validated['assignee_user_id'],
        ];

        if (!$uploadedFile instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'file' => 'A markdown file upload is required.',
            ]);
        }

        $contents = @file_get_contents($uploadedFile->getRealPath());
        if ($contents === false) {
            $this->logFailure($metadata, 'Unable to read the uploaded file.');

            throw ValidationException::withMessages([
                'file' => 'Unable to read the uploaded file.',
            ]);
        }

        if (!mb_check_encoding($contents, 'UTF-8')) {
            $this->logFailure($metadata, 'Uploaded file must be valid UTF-8 text.');

            throw ValidationException::withMessages([
                'file' => 'Uploaded file must be valid UTF-8 text.',
            ]);
        }

        $assigneeId = (int) $validated['assignee_user_id'];
        $creator = $request->user();
        $creatorId = null;
        if ($creator !== null && method_exists($creator, 'getAuthIdentifier')) {
            $identifier = $creator->getAuthIdentifier();
            if (is_numeric($identifier)) {
                $creatorId = (int) $identifier;
            }
        }

        try {
            $plan = $this->planImportService->importFromMarkdown($contents, $assigneeId, $creatorId);
        } catch (InvalidArgumentException $exception) {
            $this->logFailure($metadata, $exception->getMessage());

            throw ValidationException::withMessages([
                'file' => $exception->getMessage(),
            ]);
        }

        $this->activityLogger()->log(
            'plan.imported',
            'plan',
            (int) $plan->getKey(),
            $metadata,
        );

        return response()->json([
            'plan_id' => $plan->getKey(),
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function logFailure(array $metadata, string $error): void
    {
        $this->activityLogger()->log(
            'plan.import_failed',
            'plan',
            0,
            [...$metadata, 'error' => $error],
        );
    }
}
