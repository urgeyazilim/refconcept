<?php

declare(strict_types=1);

namespace App\Domains\Sellers\Notifications;

use App\Domains\Sellers\Models\Seller;
use App\Domains\Sellers\Models\SellerApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ApplicationApproved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly SellerApplication $application,
        private readonly Seller $seller,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('RefConcept · Satıcı başvurunuz onaylandı')
            ->greeting('Aramıza hoş geldiniz')
            ->line("{$this->application->company_name} artık RefConcept'te satış yapabilir.")
            ->line("Satıcı kodunuz: {$this->seller->seller_code}")
            ->action('Satıcı paneline git', rtrim((string) config('refconcept.urls.seller_portal'), '/'))
            ->line('Ürünlerinizi ekleyerek başlayabilirsiniz.')
            ->salutation('RefConcept');
    }
}
