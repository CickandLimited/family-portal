<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Progress\ProgressService;
use App\Services\XP\XPService;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class DeviceController extends Controller
{
    public function __construct(
        ProgressService $progressService,
        XPService $xpService,
        ActivityLogger $activityLogger
    ) {
        parent::__construct($progressService, $xpService, $activityLogger);
    }

    public function index(): ViewContract
    {
        $devices = Device::query()
            ->with('linkedUser')
            ->orderByDesc('created_at')
            ->get();

        $users = User::query()
            ->orderBy('display_name')
            ->get();

        return view('admin.devices', [
            'title' => 'Device Manager',
            'devices' => $devices,
            'users' => $users,
        ]);
    }

    public function rename(Request $request, Device $device): RedirectResponse
    {
        $validated = $request->validate([
            'friendly_name' => ['nullable', 'string', 'max:255'],
        ]);

        $friendlyName = $validated['friendly_name'] ?? null;
        if (is_string($friendlyName)) {
            $friendlyName = trim($friendlyName);
            if ($friendlyName === '') {
                $friendlyName = null;
            }
        }

        if ($device->friendly_name !== $friendlyName) {
            $previousName = $device->friendly_name;
            $device->friendly_name = $friendlyName;
            $device->save();

            $this->activityLogger()->log(
                action: 'device.renamed',
                entityType: 'device',
                entityId: $this->deviceEntityId($device),
                metadata: [
                    'previous_name' => $previousName,
                    'new_name' => $friendlyName,
                    'device_id' => $device->getKey(),
                ],
            );
        }

        return $this->redirectToDevices('Device name saved.');
    }

    public function linkUser(Request $request, Device $device): RedirectResponse
    {
        $request->merge([
            'user_id' => $request->filled('user_id') ? $request->input('user_id') : null,
        ]);

        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:user,id'],
        ]);

        $targetUserId = $validated['user_id'] ?? null;
        $targetUser = null;
        if ($targetUserId !== null) {
            /** @var User|null $targetUser */
            $targetUser = User::query()->find($targetUserId);
        }

        if ($device->linked_user_id !== $targetUserId) {
            $previousUserId = $device->linked_user_id;
            $device->linked_user_id = $targetUserId;
            $device->save();

            $metadata = [
                'previous_user_id' => $previousUserId,
                'new_user_id' => $targetUserId,
                'device_id' => $device->getKey(),
            ];

            if ($previousUserId !== null) {
                /** @var User|null $previousUser */
                $previousUser = User::query()->find($previousUserId);
                if ($previousUser instanceof User) {
                    $metadata['previous_user_name'] = $previousUser->display_name;
                }
            }

            if ($targetUser instanceof User) {
                $metadata['new_user_name'] = $targetUser->display_name;
            }

            $this->activityLogger()->log(
                action: $targetUserId !== null ? 'device.user_linked' : 'device.user_unlinked',
                entityType: 'device',
                entityId: $this->deviceEntityId($device),
                metadata: $metadata,
            );
        }

        return $this->redirectToDevices('Device link updated.');
    }

    private function redirectToDevices(string $message): RedirectResponse
    {
        return redirect()
            ->route('admin.devices.index')
            ->with('status', $message)
            ->setStatusCode(303);
    }

    private function deviceEntityId(Device $device): int
    {
        return abs(crc32($device->getKey()));
    }
}
