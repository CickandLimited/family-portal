<?php

namespace App\Services;

use App\Http\Middleware\EnsureDeviceCookie;
use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class ActivityLogger
{
    public function __construct(private readonly Request $request)
    {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function log(
        string $action,
        string $entityType,
        int $entityId,
        array $metadata = [],
        ?Device $device = null,
        ?User $user = null,
        ?string $deviceId = null,
        ?int $userId = null,
        ?Carbon $timestamp = null
    ): ActivityLog {
        $device ??= $this->resolveDevice();
        $user ??= $this->resolveUser();

        $payload = $this->normaliseMetadata($metadata);

        return ActivityLog::create([
            'timestamp' => ($timestamp ?? Carbon::now()),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'metadata' => $payload,
            'device_id' => $deviceId ?? $device?->getKey() ?? $this->request->cookies->get(EnsureDeviceCookie::COOKIE_NAME),
            'user_id' => $userId ?? $user?->getKey(),
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>|null
     */
    private function normaliseMetadata(array $metadata): ?array
    {
        if ($metadata === []) {
            return null;
        }

        return $metadata;
    }

    private function resolveDevice(): ?Device
    {
        $device = $this->request->attributes->get('device');

        return $device instanceof Device ? $device : null;
    }

    private function resolveUser(): ?User
    {
        $user = $this->request->user();

        if ($user instanceof User) {
            return $user;
        }

        if ($user instanceof Authenticatable && method_exists($user, 'getAuthIdentifier')) {
            /** @var int|string|null $id */
            $id = $user->getAuthIdentifier();
            if ($id !== null) {
                return User::query()->find($id);
            }
        }

        return null;
    }
}
