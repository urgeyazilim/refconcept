<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The e-mail carrying a verification link.
 *
 * Queued: sending mail talks to an external system, and a slow or unavailable SMTP
 * host must never turn into a slow or failed registration response.
 */
final class VerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('RefConcept · E-posta adresinizi doğrulayın')
            ->greeting('Hoş geldiniz')
            ->line('RefConcept hesabınızı kullanmaya başlamak için e-posta adresinizi doğrulayın.')
            ->action('E-postamı doğrula', $this->verificationUrl())
            ->line('Bu bağlantı 24 saat geçerlidir.')
            ->line('Bu hesabı siz oluşturmadıysanız bu e-postayı yok sayabilirsiniz.')
            ->salutation('RefConcept');
    }

    /**
     * The link points at the storefront, not the API: the customer lands on a page
     * that can show success, failure and next steps, and the page calls the API.
     */
    private function verificationUrl(): string
    {
        $base = rtrim((string) config('refconcept.urls.storefront'), '/');

        return $base.'/auth/verify-email?token='.urlencode($this->token);
    }
}
