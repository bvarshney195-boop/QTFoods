<?php

namespace App\Providers;

use App\Shared\Deployment\ProductionEnvironmentGuard;
use App\Shared\Observability\RequestContext;
use App\Shared\Outbox\ConfiguredOutboxTransport;
use App\Shared\Outbox\OutboxTransport;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RequestContext::class);
        $this->app->singleton(OutboxTransport::class, ConfiguredOutboxTransport::class);
    }

    public function boot(ProductionEnvironmentGuard $deployment): void
    {
        if ($this->app->environment('production')) {
            $deployment->enforce();
            URL::forceScheme('https');
        }

        RateLimiter::for('identity-login', fn (Request $request) => Limit::perMinute(10)->by(
            $this->rateKey('login', $request, (string) $request->input('email'))
        ));
        RateLimiter::for('identity-mfa-challenge', fn (Request $request) => Limit::perMinute(10)->by(
            $this->rateKey('mfa-challenge', $request, (string) $request->input('challenge_id'))
        ));
        RateLimiter::for('identity-email', fn (Request $request) => Limit::perMinute(5)->by(
            $this->rateKey((string) $request->route()?->uri(), $request, (string) $request->input('email'))
        ));
        RateLimiter::for('identity-link-read', fn (Request $request) => Limit::perMinute(30)->by(
            $this->rateKey('link-read', $request, (string) $request->route('token'))
        ));
        RateLimiter::for('identity-link-consume', fn (Request $request) => Limit::perMinute(10)->by(
            $this->rateKey((string) $request->route()?->uri(), $request, (string) $request->input('token'))
        ));
        RateLimiter::for('identity-account-security', fn (Request $request) => Limit::perMinute(10)->by(
            $this->rateKey(
                (string) $request->route()?->uri(),
                $request,
                (string) ($request->user()?->getAuthIdentifier() ?? 'guest')
            )
        ));

        Queue::failing(static function (JobFailed $event): void {
            Log::critical('queue_job_failed', [
                'event' => 'queue_job_failed',
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'job_id' => $event->job->uuid() ?? $event->job->getJobId(),
                'job_name' => $event->job->resolveName(),
                'attempts' => $event->job->attempts(),
                'error_type' => class_basename($event->exception),
            ]);
        });
    }

    private function rateKey(string $operation, Request $request, string $identity): string
    {
        return hash('sha256', implode('|', [
            $operation,
            Str::lower(trim($identity)),
            (string) $request->ip(),
        ]));
    }
}
