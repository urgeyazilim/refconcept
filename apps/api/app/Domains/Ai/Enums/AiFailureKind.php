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
            self::CostCapExceeded, self::NoRouteConfigured, self::KillSwitchEngaged => false,
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
             * A safety refusal *does* warrant a fallback: providers draw the line in
             * different places, and one refusing to render a bedroom is a provider
             * problem rather than a request problem.
             */
            default => true,
        };
    }
}
