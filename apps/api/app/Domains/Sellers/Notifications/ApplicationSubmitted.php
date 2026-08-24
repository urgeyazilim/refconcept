<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Notifications;

use App\Domains\Sellers\Models\SellerApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ApplicationSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly SellerApplication $application) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('RefConcept · Satıcı başvurunuz alındı')
            ->greeting('Başvurunuz bize ulaştı')
            ->line("{$this->application->company_name} adına yaptığınız satıcı başvurusu inceleme sırasına alındı.")
            ->line('Ekibimiz belgelerinizi inceledikten sonra sonucu e-posta ile bildirecek.')
            ->action('Başvurumu görüntüle', $this->portalUrl())
            ->salutation('RefConcept');
    }

    private function portalUrl(): string
    {
        return rtrim((string) config('refconcept.urls.seller_portal'), '/').'/onboarding';
    }
}
