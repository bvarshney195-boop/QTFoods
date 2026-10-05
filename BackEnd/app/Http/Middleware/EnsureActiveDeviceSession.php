<?php

namespace App\Http\Middleware;

use App\Modules\Foundation\Application\DeviceSessionService;
use App\Modules\Foundation\Domain\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActiveDeviceSession
{
    public function __construct(private readonly DeviceSessionService $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->is_demo && ! config('deployment.allow_demo_authentication', false)) {
            $request->session()->invalidate();

            return response()->json(['error' => [
                'code' => 'DEMO_IDENTITY_DISABLED',
                'message' => 'Demo identities are disabled in this environment.',
            ]], 401);
        }
        $this->sessions->ensure($user, $request);

        return $next($request);
    }
}
