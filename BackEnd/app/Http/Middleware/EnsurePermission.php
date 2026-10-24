<?php

namespace App\Http\Middleware;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Domain\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePermission
{
    public function __construct(private readonly SessionService $sessions) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->sessions->can($user, $request, $permission)) {
            throw new AuthorizationException('You are not authorised for this ERP action.');
        }

        return $next($request);
    }
}
