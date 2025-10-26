<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Illuminate\Support\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureDeviceCookie
{
    public const COOKIE_NAME = 'fp_device_id';
    private const COOKIE_LIFETIME_MINUTES = 60 * 24 * 365 * 5;

    public function handle(Request $request, Closure $next): Response
    {
        $deviceId = $request->cookies->get(self::COOKIE_NAME);
        $cookieMissing = empty($deviceId);

        if ($cookieMissing) {
            $deviceId = (string) Str::uuid();
        }

        $device = $this->persistDevice($deviceId);
        $request->attributes->set('device', $device);

        /** @var Response $response */
        $response = $next($request);

        if ($cookieMissing) {
            $response->headers->setCookie(
                Cookie::make(
                    name: self::COOKIE_NAME,
                    value: $device->getKey(),
                    minutes: self::COOKIE_LIFETIME_MINUTES,
                    path: '/',
                    domain: null,
                    secure: (bool) config('session.secure', false),
                    httpOnly: true,
                    raw: false,
                    sameSite: 'lax'
                )
            );
        }

        return $response;
    }

    private function persistDevice(string $deviceId): Device
    {
        $now = Carbon::now();

        /** @var Device|null $device */
        $device = Device::query()->find($deviceId);

        if ($device === null) {
            $device = new Device();
            $device->id = $deviceId;
            $device->created_at = $now;
        }

        $device->last_seen_at = $now;
        $device->save();

        return $device;
    }
}
