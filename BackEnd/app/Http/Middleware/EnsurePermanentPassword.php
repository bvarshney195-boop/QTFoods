<?php

namespace App\Http\Middleware;

use App\Modules\Foundation\Domain\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePermanentPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->password_changed_at === null) {
            return response()->json(['error' => [
                'code' => 'PASSWORD_CHANGE_REQUIRED',
                'message' => 'Replace your temporary password before accessing ERP data.',
            ]], 409);
        }

        return $next($request);
    }
}
