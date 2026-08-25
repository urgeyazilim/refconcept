<?php

declare(strict_types=1);

namespace App\Domains\Payments\Http\Controllers;

use App\Domains\Payments\Exceptions\GatewayUnavailable;
use App\Domains\Payments\Services\WebhookInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where providers tell us what happened.
 *
 * Unauthenticated by nature: the caller is a bank's server, not a session. What stands in
 * for authentication is the signature the adapter verifies over the exact bytes received,
 * and the fact that nothing here does any domain work — the row is written, a job is
 * queued, and the provider is answered.
 *
 * **The answer is always 200 for anything we successfully stored**, including duplicates
 * and events we do not understand. That is not laziness: a provider told that a duplicate
 * failed will resend it, forever, and a retry storm on a payment endpoint is a self
 * inflicted outage. The only refusals are a body too large to be worth parsing and a
 * signature that did not check out.
 */
final class PaymentWebhookController
{
    public function __construct(private readonly WebhookInbox $inbox) {}

    public function __invoke(Request $request, string $gateway): JsonResponse
    {
        $body = $request->getContent();

        $limit = (int) config('payments.webhooks.max_body_bytes', 262144);

        if (mb_strlen($body, '8bit') > $limit) {
            /*
             * Anybody who knows this URL can post to it. Parsing a hundred megabytes of
             * JSON before deciding it was not signed is a denial of service with extra
             * steps, so the size is checked before anything else looks at the body.
             */
            return response()->json(['message' => 'Bildirim gövdesi çok büyük.'], 413);
        }

        try {
            $outcome = $this->inbox->receive($gateway, $this->headers($request), $body);
        } catch (GatewayUnavailable) {
            // An unknown provider name is a 404 rather than a 400: this endpoint should
            // not confirm which integrations exist to somebody probing the URL space.
            return response()->json(['message' => 'Bulunamadı.'], 404);
        }

        if (! $outcome['verified']) {
            return response()->json(['message' => 'İmza doğrulanamadı.'], 401);
        }

        return response()->json([
            'received' => true,
            'duplicate' => $outcome['duplicate'],
        ]);
    }

    /**
     * @return array<string, list<string>|string>
     */
    private function headers(Request $request): array
    {
        /** @var array<string, list<string>|string> $headers */
        $headers = $request->headers->all();

        return $headers;
    }
}
