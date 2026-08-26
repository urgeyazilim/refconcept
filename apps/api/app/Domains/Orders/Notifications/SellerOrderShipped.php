<?php

declare(strict_types=1);

namespace App\Domains\Orders\Notifications;

use App\Domains\Orders\Models\SellerOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the customer one of their parcels has left.
 *
 * Says *which* seller, because on a multi-seller order "kargoya verildi" without a name is
 * a message that raises more questions than it answers — the customer is waiting on three
 * parcels and has just been told one of them moved.
 */
final class SellerOrderShipped extends Notification implements ShouldQueue
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
        $this->sellerOrder->loadMissing(['order', 'seller']);

        $seller = $this->sellerOrder->seller->display_name ?? 'Satıcı';
        $orderNumber = $this->sellerOrder->order->order_number ?? '';

        return (new MailMessage)
            ->subject('RefConcept · Siparişiniz kargoya verildi')
            ->greeting('Siparişiniz yola çıktı')
            ->line("{$seller} tarafından gönderilen ürünleriniz kargoya verildi.")
            ->line("Sipariş numaranız: {$orderNumber}")
            ->action('Siparişimi gör', rtrim((string) config('refconcept.urls.storefront'), '/').'/account/orders')
            ->salutation('RefConcept');
    }
}
