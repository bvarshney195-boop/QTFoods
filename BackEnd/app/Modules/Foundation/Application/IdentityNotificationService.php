<?php

namespace App\Modules\Foundation\Application;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class IdentityNotificationService
{
    public function invitation(string $email, string $name, string $token, string $expiresAt): array
    {
        $url = $this->url('invite', $email, $token);
        $body = "Hello {$name},\n\nYou have been invited to Q & T Foods ERP. "
            ."Open the link below to choose your password. The link expires at {$expiresAt}.\n\n{$url}\n\n"
            .'If you were not expecting this invitation, you can ignore this message.';

        return $this->deliver($email, 'Your Q & T Foods ERP invitation', $body, $url);
    }

    public function passwordReset(string $email, string $name, string $token, string $expiresAt): array
    {
        $url = $this->url('reset', $email, $token);
        $body = "Hello {$name},\n\nA password reset was requested for your Q & T Foods ERP account. "
            ."Open the link below to choose a new password. The link expires at {$expiresAt}.\n\n{$url}\n\n"
            .'If you did not request this, do not use the link and review your active device sessions.';

        return $this->deliver($email, 'Reset your Q & T Foods ERP password', $body, $url);
    }

    public function loginOtp(string $email, string $name, string $code, string $expiresAt): array
    {
        $body = "Hello {$name},\n\nYour Q & T Foods ERP sign-in code is {$code}. "
            ."It expires at {$expiresAt}.\n\n"
            .'Do not share this code. If you did not request it, you can ignore this message and review your account security.';

        $result = $this->deliver($email, 'Your Q & T Foods ERP sign-in code', $body, '');
        if (config('qtfoods.identity.preview_links', false)) {
            $result['preview_code'] = $code;
            unset($result['preview_url']);
        }

        return $result;
    }

    public function emailVerification(string $email, string $name, string $token, string $expiresAt): array
    {
        $url = $this->url('verify', $email, $token);
        $body = "Hello {$name},\n\nVerify this email address for your Q & T Foods ERP account. "
            ."The link expires at {$expiresAt}.\n\n{$url}\n\n"
            .'If you did not request this, do not use the link.';

        return $this->deliver($email, 'Verify your Q & T Foods ERP email', $body, $url);
    }

    private function deliver(string $email, string $subject, string $body, string $previewUrl): array
    {
        try {
            Mail::raw($body, function ($message) use ($email, $subject): void {
                $message->to($email)->subject($subject);
            });
            $status = 'SENT';
        } catch (\Throwable $exception) {
            Log::error('Identity email delivery failed.', [
                'recipient_domain' => str_contains($email, '@') ? strrchr($email, '@') : null,
                'exception' => $exception,
            ]);
            $status = 'FAILED';
        }

        $result = ['channel' => 'EMAIL', 'status' => $status];
        if (config('qtfoods.identity.preview_links', false)) {
            $result['preview_url'] = $previewUrl;
        }

        return $result;
    }

    private function url(string $flow, string $email, string $token): string
    {
        $base = rtrim((string) config('qtfoods.identity.frontend_url', 'http://localhost:5173'), '/');

        return $base.'/?'.http_build_query([
            'flow' => $flow,
            'email' => $email,
            'token' => $token,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
