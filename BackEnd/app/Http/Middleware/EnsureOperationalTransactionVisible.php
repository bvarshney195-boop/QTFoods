<?php

namespace App\Http\Middleware;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Domain\User;
use App\Shared\Visibility\TransactionVisibilityPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class EnsureOperationalTransactionVisible
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly TransactionVisibilityPolicy $visibility,
    ) {}

    public function handle(Request $request, Closure $next, string $transactionType, string $routeParameter): Response
    {
        /** @var User $user */
        $user = $request->user();
        $transactionId = $request->route($routeParameter);
        $scope = $request->attributes->get('erp.context');
        if (! is_string($transactionId) || ! is_array($scope)) {
            throw new NotFoundHttpException('Transaction not found.');
        }

        $this->visibility->assertOperationallyVisible(
            $this->sessions->permissions($user, $request),
            $transactionType,
            $transactionId,
            ['company_id' => $scope['company_id'] ?? null, 'plant_id' => $scope['plant_id'] ?? null],
        );

        return $next($request);
    }
}
