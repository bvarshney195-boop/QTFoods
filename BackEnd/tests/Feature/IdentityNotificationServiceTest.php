<?php

namespace Tests\Feature;

use App\Modules\Foundation\Application\IdentityNotificationService;
use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class IdentityNotificationServiceTest extends TestCase
{
    public function test_laravel_resolves_the_official_resend_https_transport(): void
    {
        $this->configureResend();
        app('mail.manager')->purge('resend');

        $transport = app('mail.manager')->mailer('resend')->getSymfonyTransport();

        self::assertInstanceOf(ResendTransport::class, $transport);
        self::assertSame('resend', (string) $transport);
        self::assertSame('https', config('mail.mailers.resend.scheme'));
    }

    public function test_all_identity_messages_report_success_through_the_default_mailer(): void
    {
        $this->configureResend();
        Mail::shouldReceive('raw')->times(4)->andReturnNull();

        $notifications = app(IdentityNotificationService::class);
        $results = [
            $notifications->loginOtp('admin@qtfoods.in', 'Administrator', '123456', now()->addMinutes(5)->toIso8601String()),
            $notifications->invitation('invitee@qtfoods.in', 'Invitee', 'invite-token', now()->addHour()->toIso8601String()),
            $notifications->passwordReset('admin@qtfoods.in', 'Administrator', 'reset-token', now()->addHour()->toIso8601String()),
            $notifications->emailVerification('admin@qtfoods.in', 'Administrator', 'verify-token', now()->addHour()->toIso8601String()),
        ];

        foreach ($results as $result) {
            self::assertSame(['channel' => 'EMAIL', 'status' => 'SENT'], $result);
        }
    }

    public function test_resend_failure_is_reported_without_claiming_delivery(): void
    {
        $this->configureResend();
        $failure = new RuntimeException('Simulated Resend API failure.');
        Mail::shouldReceive('raw')->once()->andThrow($failure);
        Log::shouldReceive('error')->once()->with(
            'Identity email delivery failed.',
            Mockery::on(static fn (array $context): bool => $context['mailer'] === 'resend'
                && $context['transport'] === 'resend'
                && $context['recipient_domain'] === '@qtfoods.in'
                && $context['error_type'] === RuntimeException::class
                && $context['exception'] === $failure
                && ! array_key_exists('recipient', $context)
                && ! array_key_exists('body', $context)),
        );

        $result = app(IdentityNotificationService::class)->passwordReset(
            'admin@qtfoods.in',
            'Administrator',
            'reset-token',
            now()->addHour()->toIso8601String(),
        );

        self::assertSame(['channel' => 'EMAIL', 'status' => 'FAILED'], $result);
    }

    private function configureResend(): void
    {
        config()->set([
            'mail.default' => 'resend',
            'mail.mailers.resend.transport' => 'resend',
            'mail.mailers.resend.scheme' => 'https',
            'mail.from.address' => 'noreply@qtfoods.in',
            'mail.from.name' => 'Q & T Foods ERP',
            'services.resend.key' => 're_Q7m2R9x4K6p8V1c5N3q0W2z7L4b9S6d8',
            'services.resend.base_url_override' => null,
            'qtfoods.identity.preview_links' => false,
        ]);
    }
}
