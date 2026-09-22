<?php

declare(strict_types=1);

namespace App\Domains\Ai\Enums;

/**
 * Why a call to a provider did not produce an answer.
 *
 * The distinction that matters is retryable or not, and it is not obvious from the
 * message. A timeout is worth trying again; a request the provider refused on safety
 * grounds will be refused identically every time, and retrying it three times wastes
 * ten seconds of a customer's patience to arrive at the same place.
 */
enum AiFailureKind: string
{
    case Timeout = 'timeout';
    case RateLimited = 'rate_limited';
    case ProviderError = 'provider_error';
    case NetworkError = 'network_error';

    /** The provider answered, but not in a shape the application can read. */
    case MalformedOutput = 'malformed_output';

    case SafetyRefusal = 'safety_refusal';
    case InvalidRequest = 'invalid_request';
    case AuthenticationFailed = 'authentication_failed';

    /** The call would have cost more than the route allows. */
    case CostCapExceeded = 'cost_cap_exceeded';

    case NoRouteConfigured = 'no_route_configured';
    case KillSwitchEngaged = 'kill_switch_engaged';

    /**
     * The provider's own account has run out of money.
     *
     * Separate from {@see RateLimited} because OpenAI reports both as a 429 and they are
     * opposite problems. A rate limit passes on its own and is worth waiting out; an empty
     * account does not, and four attempts at it are four attempts at "no". The design that
     * uncovered this told the customer "İstek sınırı. Lütfen tekrar deneyin" — true of
     * neither half — while the sentence that would have fixed it, "You have no credits
     * remaining", sat in a failure row nobody reads.
     *
     * Not the customer's balance: {@see CostCapExceeded} is that. This is the platform's
     * own bill, and nothing a customer does will move it.
     */
    case ProviderOutOfCredit = 'provider_out_of_credit';

    public function label(): string
    {
        return match ($this) {
            self::Timeout => 'Zaman aşımı',
            self::RateLimited => 'İstek sınırı',
            self::ProviderError => 'Sağlayıcı hatası',
            self::NetworkError => 'Ağ hatası',
            self::MalformedOutput => 'Geçersiz yanıt biçimi',
            self::SafetyRefusal => 'Güvenlik reddi',
            self::InvalidRequest => 'Geçersiz istek',
            self::AuthenticationFailed => 'Kimlik doğrulama hatası',
            self::CostCapExceeded => 'Maliyet sınırı aşıldı',
            self::NoRouteConfigured => 'Yönlendirme tanımlı değil',
            self::KillSwitchEngaged => 'AI geçici olarak kapalı',
            self::ProviderOutOfCredit => 'Sağlayıcı hesabında bakiye yok',
        };
    }

    /** Whether trying the same provider again could plausibly work. */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::Timeout, self::RateLimited, self::NetworkError, self::ProviderError => true,

            /*
             * Malformed output is retryable on purpose. A model that returned prose
             * where JSON was asked for will often comply on a second attempt, and the
             * alternative is failing a customer's design over one bad sample.
             */
            self::MalformedOutput => true,

            // These will fail identically however many times they are tried.
            self::SafetyRefusal, self::InvalidRequest, self::AuthenticationFailed,
            self::CostCapExceeded, self::NoRouteConfigured, self::KillSwitchEngaged,
            self::ProviderOutOfCredit => false,
        };
    }

    /** Whether falling back to a different provider is worth attempting. */
    public function warrantsFallback(): bool
    {
        return match ($this) {
            // A configuration or policy problem follows us to the fallback.
            self::InvalidRequest, self::CostCapExceeded, self::NoRouteConfigured,
            self::KillSwitchEngaged => false,

            /*
             * An empty account is worth a fallback, and only when the fallback is somebody
             * else's. The route's second model is usually the same provider's — the plan
             * falls back from Astra to GPT-5.5 — and asking the same empty account again
             * under a different model name is the same "no" with a second bill attached to
             * the waiting. The gateway checks the provider before it tries.
             */
            self::ProviderOutOfCredit => true,

            /*
             * A safety refusal *does* warrant a fallback: providers draw the line in
             * different places, and one refusing to render a bedroom is a provider
             * problem rather than a request problem.
             */
            default => true,
        };
    }
}
