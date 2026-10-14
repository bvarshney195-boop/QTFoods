<?php

namespace App\Http\Middleware;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Domain\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureRole
{
    public function __construct(private readonly SessionService $sessions) {}

    public function handle(Request $request, Closure $next, string $roleCode): Response
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->sessions->hasRole($user, $request, $roleCode)) {
            throw new AuthorizationException('Only an ERP Administrator can delete ERP entries.');
        }

        return $next($request);
    }
}
