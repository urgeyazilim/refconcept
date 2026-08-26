<?php

declare(strict_types=1);

namespace App\Domains\Orders\Notifications;

use App\Domains\Orders\Models\SellerOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a seller they have something to pack.
 *
 * Carries the seller's own number and their own total. Nothing about the rest of the
 * basket appears: a seller has no business knowing who else the customer bought from, or
 * what they paid them.
 */
final class SellerOrderPlaced extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly SellerOrder $sellerOrder) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $total = number_format($this->sellerOrder->total_minor / 100, 2, ',', '.');
        $count = (int) $this->sellerOrder->items()->sum('quantity');

        return (new MailMessage)
            ->subject('RefConcept · Yeni siparişiniz var')
            ->greeting('Yeni sipariş')
            ->line("Sipariş numarası: {$this->sellerOrder->seller_order_number}")
            ->line("{$count} ürün · {$total} {$this->sellerOrder->currency}")
            ->line('Siparişi onaylayıp hazırlamaya başlayabilirsiniz.')
            ->action('Siparişi aç', rtrim((string) config('refconcept.urls.seller_portal'), '/').'/orders')
            ->salutation('RefConcept');
    }
}
