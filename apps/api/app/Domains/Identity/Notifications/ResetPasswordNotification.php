<?php

declare(strict_types=1);

namespace App\Domains\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The e-mail carrying a password reset link.
 */
final class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly int $ttlMinutes,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('RefConcept · Parola sıfırlama')
            ->greeting('Parolanızı sıfırlayın')
            ->line('Hesabınız için bir parola sıfırlama talebi aldık.')
            ->action('Yeni parola belirle', $this->resetUrl())
            ->line("Bu bağlantı {$this->ttlMinutes} dakika geçerlidir.")
            ->line('Bu talebi siz yapmadıysanız hiçbir işlem yapmanıza gerek yok; parolanız değişmez.')
            ->salutation('RefConcept');
    }

    private function resetUrl(): string
    {
        $base = rtrim((string) config('refconcept.urls.storefront'), '/');

        return $base.'/auth/reset-password?token='.urlencode($this->token);
    }
}
