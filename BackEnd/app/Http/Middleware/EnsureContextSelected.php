<?php

namespace App\Http\Middleware;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Domain\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureContextSelected
{
    public function __construct(private readonly SessionService $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();
        $context = $this->sessions->currentContext($user, $request);

        if (! $context) {
            return response()->json([
                'error' => [
                    'code' => 'CONTEXT_REQUIRED',
                    'message' => 'Select an authorised company and plant before continuing.',
                ],
            ], 409);
        }

        $request->attributes->set('erp.context', $context);

        return $next($request);
    }
}
