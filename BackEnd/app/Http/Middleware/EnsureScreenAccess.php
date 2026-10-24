<?php

namespace App\Http\Middleware;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Domain\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureScreenAccess
{
    public function __construct(private readonly SessionService $sessions) {}

    public function handle(Request $request, Closure $next, string $screenCode): Response
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->sessions->canViewScreen($user, $request, $screenCode)) {
            throw new AuthorizationException('You are not authorised to access this ERP screen.');
        }

        return $next($request);
    }
}
