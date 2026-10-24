<?php

namespace App\Modules\Foundation\Http\Controllers\Concerns;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Domain\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

trait BuildsAdminContext
{
    abstract protected function sessionService(): SessionService;

    protected function selectedScope(Request $request, bool $plantRequired = false): array
    {
        $context = $request->attributes->get('erp.context');
        if (! is_array($context) || ! is_string($context['company_id'] ?? null)) {
            throw ValidationException::withMessages([
                'context' => ['Foundation administration requires an active organisation context.'],
            ]);
        }
        $plantId = $context['plant_id'] ?? null;
        if ($plantRequired && ! is_string($plantId)) {
            throw ValidationException::withMessages([
                'context' => ['Select a plant before using this administration screen.'],
            ]);
        }

        return [
            'company_id' => $context['company_id'],
            'plant_id' => is_string($plantId) ? $plantId : null,
        ];
    }

    protected function commandContext(Request $request, bool $versionRequired): array
    {
        /** @var User $user */
        $user = $request->user();

        $context = $this->selectedScope($request);
        $command = $context + [
            'actor_id' => (string) $user->id,
            'permissions' => $this->sessionService()->permissions($user, $request),
            'idempotency_key' => $this->idempotencyKey($request),
            'correlation_id' => $this->correlationId($request),
        ];
        if ($versionRequired) {
            $command['expected_version'] = $this->expectedVersion($request);
        }

        return $command;
    }

    protected function currentPermissions(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        return $this->sessionService()->permissions($user, $request);
    }

    private function expectedVersion(Request $request): int
    {
        $value = trim((string) $request->header('If-Match'));
        if ($value === '') {
            throw ValidationException::withMessages([
                'if_match' => ['The If-Match header is required for this administration command.'],
            ]);
        }
        if (preg_match('/^(?:W\/)?"(\d+)"$/', $value, $matches)) {
            $value = $matches[1];
        }
        if (! ctype_digit($value) || (int) $value < 1) {
            throw ValidationException::withMessages([
                'if_match' => ['The If-Match header must contain a positive record version.'],
            ]);
        }

        return (int) $value;
    }

    private function idempotencyKey(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' && config('qtfoods.require_idempotency', true)) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['The Idempotency-Key header is required.'],
            ]);
        }
        if (strlen($key) > 160) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['The Idempotency-Key header may not exceed 160 characters.'],
            ]);
        }

        return $key !== '' ? $key : (string) Str::uuid();
    }

    private function correlationId(Request $request): ?string
    {
        $value = trim((string) $request->header('X-Correlation-ID'));
        if ($value !== '' && ! Str::isUuid($value)) {
            throw ValidationException::withMessages([
                'correlation_id' => ['The X-Correlation-ID header must be a UUID.'],
            ]);
        }

        return $value !== '' ? $value : null;
    }
}
