<?php

declare(strict_types=1);

namespace App\Domains\Payments\Services;

use App\Domains\Administration\Services\Features;
use App\Domains\Payments\Contracts\PaymentGateway;
use App\Domains\Payments\Exceptions\GatewayUnavailable;

/**
 * Which payment providers exist, and which one is used.
 *
 * A named lookup rather than "whatever is bound in the container", because the choice of
 * who takes the money is a configuration decision that should be readable in one place
 * and impossible to make by accident. A provider that is present but not `enabled` cannot
 * be selected at all — a half-finished credential in an environment file must not quietly
 * become the gateway that charges a customer.
 *
 * Adapters are registered rather than discovered. Discovery would mean a class dropped in
 * a directory changes who takes money, which is a lot of authority to give a filename.
 */
final class GatewayRegistry
{
    /**
     * Which payment methods an operator may switch off from the admin panel.
     *
     * A method with no flag is governed by configuration alone. That is deliberate: the
     * card gateway is not something to turn off by accident during an incident, and a
     * checkout with no way to pay is a worse outage than a slow one.
     *
     * @var array<string, string>
     */
    private const FLAGS = ['bank_transfer' => 'checkout.bank-transfer'];

    /** @var array<string, PaymentGateway> */
    private array $gateways = [];

    public function __construct(private readonly Features $features) {}

    public function register(PaymentGateway $gateway): void
    {
        $this->gateways[$gateway->name()] = $gateway;
    }

    /**
     * The gateway to use when the caller did not name one.
     *
     * @throws GatewayUnavailable
     */
    public function default(): PaymentGateway
    {
        return $this->get((string) config('payments.default', 'fake'));
    }

    /**
     * @throws GatewayUnavailable
     */
    public function get(string $name): PaymentGateway
    {
        if (! isset($this->gateways[$name])) {
            throw GatewayUnavailable::unknown($name);
        }

        if (! $this->isEnabled($name)) {
            throw GatewayUnavailable::disabled($name);
        }

        return $this->gateways[$name];
    }

    /**
     * The gateway that owns an existing payment, enabled or not.
     *
     * Switching a provider off must not strand the payments already taken through it: a
     * refund, a reconciliation or a late webhook still has to reach the adapter that
     * understands them. Only *starting* a new payment is gated on `enabled`.
     *
     * @throws GatewayUnavailable
     */
    public function forExistingPayment(string $name): PaymentGateway
    {
        if (! isset($this->gateways[$name])) {
            throw GatewayUnavailable::unknown($name);
        }

        return $this->gateways[$name];
    }

    public function isEnabled(string $name): bool
    {
        if (! (bool) config("payments.gateways.{$name}.enabled", false)) {
            return false;
        }

        /*
         * Configuration says which providers this deployment has at all; a flag says which
         * of them are taking money right now. The second is an operational decision — a
         * bank that has stopped answering, a receiving account being changed — and it
         * needs to be one click rather than a deploy.
         *
         * Only starting a payment is gated. `forExistingPayment()` stays open, so a refund
         * or a late notification for a payment already taken still reaches its adapter.
         */
        $flag = self::FLAGS[$name] ?? null;

        return $flag === null || $this->features->enabled($flag);
    }

    /**
     * The gateways a customer may actually pay through, in configuration order.
     *
     * @return list<string>
     */
    public function available(): array
    {
        $names = array_keys($this->gateways);

        return array_values(array_filter($names, fn (string $name): bool => $this->isEnabled($name)));
    }

    public function has(string $name): bool
    {
        return isset($this->gateways[$name]);
    }
}
