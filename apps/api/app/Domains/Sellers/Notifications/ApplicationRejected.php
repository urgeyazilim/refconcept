<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Notifications;

use App\Domains\Sellers\Models\SellerApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ApplicationRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly SellerApplication $application,
        private readonly string $reason,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * The reason is included deliberately. A rejection the applicant cannot act on is
     * a support ticket waiting to happen, and often the problem is a blurred scan.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('RefConcept · Satıcı başvurunuz hakkında')
            ->greeting('Başvurunuz onaylanmadı')
            ->line("{$this->application->company_name} adına yaptığınız başvuru şu gerekçeyle onaylanmadı:")
            ->line($this->reason)
            ->line('Eksikleri tamamlayarak yeni bir başvuru oluşturabilirsiniz.')
            ->action('Yeni başvuru oluştur', rtrim((string) config('refconcept.urls.seller_portal'), '/').'/onboarding')
            ->salutation('RefConcept');
    }
}
