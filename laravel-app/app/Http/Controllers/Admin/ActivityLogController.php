<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Progress\ProgressService;
use App\Services\XP\XPService;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class ActivityLogController extends Controller
{
    private const PAGE_SIZE = 25;

    public function __construct(
        ProgressService $progressService,
        XPService $xpService,
        ActivityLogger $activityLogger
    ) {
        parent::__construct($progressService, $xpService, $activityLogger);
    }

    public function index(Request $request): ViewContract
    {
        [$filters, $formState, $page] = $this->extractFilters($request);
        $entriesContext = $this->buildEntriesContext($filters, $page);

        $actionOptions = ActivityLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->filter()
            ->values();

        $entityTypeOptions = ActivityLog::query()
            ->select('entity_type')
            ->distinct()
            ->orderBy('entity_type')
            ->pluck('entity_type')
            ->filter()
            ->values();

        $deviceOptions = Device::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Device $device) => [
                'value' => $device->getKey(),
                'label' => $device->friendly_name ?: 'Device ' . $device->getKey(),
            ]);

        $userOptions = User::query()
            ->orderBy('display_name')
            ->get()
            ->map(fn (User $user) => [
                'value' => (string) $user->getKey(),
                'label' => $user->display_name,
            ]);

        return view('admin.activity', [
            'title' => 'Activity Log',
            'actionOptions' => $actionOptions,
            'entityTypeOptions' => $entityTypeOptions,
            'deviceOptions' => $deviceOptions,
            'userOptions' => $userOptions,
            'filterForm' => $formState,
            ...$entriesContext,
        ]);
    }

    public function entries(Request $request): ViewContract
    {
        [$filters, , $page] = $this->extractFilters($request);
        $context = $this->buildEntriesContext($filters, $page);

        return view('admin.partials.activity-log-table', [
            'request' => $request,
            ...$context,
        ]);
    }

    /**
     * @return array{0: array<string, string|int|null>, 1: array<string, string>, 2: int}
     */
    private function extractFilters(Request $request): array
    {
        $action = $this->cleanString($request->query('action'));
        $entityType = $this->cleanString($request->query('entity_type'));
        $deviceId = $this->cleanString($request->query('device_id'));
        $userId = $this->parseInt($request->query('user_id'));
        $page = max(1, $this->parseInt($request->query('page')) ?? 1);

        $filters = [
            'action' => $action,
            'entity_type' => $entityType,
            'device_id' => $deviceId,
            'user_id' => $userId,
        ];

        $formState = [
            'action' => $action ?? '',
            'entity_type' => $entityType ?? '',
            'device_id' => $deviceId ?? '',
            'user_id' => $userId !== null ? (string) $userId : '',
        ];

        return [$filters, $formState, $page];
    }

    /**
     * @param array<string, string|int|null> $filters
     * @return array{entries: Collection<int, array<string, mixed>>, pagination: array<string, mixed>}
     */
    private function buildEntriesContext(array $filters, int $page): array
    {
        $query = ActivityLog::query()
            ->with(['device', 'user'])
            ->orderByDesc('timestamp');

        if ($filters['action']) {
            $query->where('action', $filters['action']);
        }
        if ($filters['entity_type']) {
            $query->where('entity_type', $filters['entity_type']);
        }
        if ($filters['device_id']) {
            $query->where('device_id', $filters['device_id']);
        }
        if ($filters['user_id'] !== null) {
            $query->where('user_id', $filters['user_id']);
        }

        $logs = $query
            ->skip(($page - 1) * self::PAGE_SIZE)
            ->take(self::PAGE_SIZE + 1)
            ->get();

        $hasNext = $logs->count() > self::PAGE_SIZE;
        $visible = $logs->take(self::PAGE_SIZE);

        $entries = $visible->map(fn (ActivityLog $entry) => $this->serialiseEntry($entry));

        $pagination = [
            'page' => $page,
            'has_next' => $hasNext,
            'has_previous' => $page > 1,
            'next_page' => $hasNext ? $page + 1 : null,
            'previous_page' => $page > 1 ? $page - 1 : null,
        ];

        return [
            'entries' => $entries,
            'pagination' => $pagination,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialiseEntry(ActivityLog $entry): array
    {
        $deviceLabel = null;
        if ($entry->device instanceof Device) {
            $deviceLabel = $entry->device->friendly_name ?: 'Device ' . $entry->device->getKey();
        } elseif ($entry->device_id) {
            $deviceLabel = 'Device ' . $entry->device_id;
        }

        $userLabel = null;
        if ($entry->user instanceof User) {
            $userLabel = $entry->user->display_name;
        } elseif ($entry->user_id !== null) {
            $userLabel = 'User ' . $entry->user_id;
        }

        $metadata = $entry->metadata;
        if ($metadata === null) {
            $metadataJson = 'null';
        } else {
            $metadataJson = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null';
        }

        $entityIdentifier = $entry->entity_id;
        if ($entry->entity_type === 'device') {
            $entityIdentifier = $metadata['device_id'] ?? $entry->entity_id;
        }

        return [
            'id' => $entry->getKey(),
            'timestamp' => $entry->timestamp,
            'timestamp_display' => optional($entry->timestamp)->format('Y-m-d H:i:s \U\T\C'),
            'action' => $entry->action,
            'entity_type' => $entry->entity_type,
            'entity_id' => $entry->entity_id,
            'entity_identifier' => $entityIdentifier,
            'metadata' => $metadata,
            'metadata_json' => $metadataJson,
            'device_label' => $deviceLabel,
            'user_label' => $userLabel,
        ];
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function parseInt(mixed $value): ?int
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = (string) $value;
        if (! Str::of($string)->isNotEmpty()) {
            return null;
        }

        return filter_var($string, FILTER_VALIDATE_INT, [
            'options' => [
                'default' => null,
            ],
        ]);
    }
}
