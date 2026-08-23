<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Actions\AuthenticateUser;
use App\Domains\Identity\Actions\RegisterUser;
use App\Domains\Identity\Exceptions\AuthenticationFailed;
use App\Domains\Identity\Http\Requests\ForgotPasswordRequest;
use App\Domains\Identity\Http\Requests\LoginRequest;
use App\Domains\Identity\Http\Requests\RegisterRequest;
use App\Domains\Identity\Http\Requests\ResetPasswordRequest;
use App\Domains\Identity\Http\Resources\UserResource;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Services\EmailVerificationService;
use App\Domains\Identity\Services\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Public authentication surface.
 *
 * Every endpoint here is unauthenticated and therefore rate limited at the route
 * level. Responses are deliberately uniform where distinguishing outcomes would
 * disclose whether an account exists.
 */
final class AuthController
{
    public function register(RegisterRequest $request, RegisterUser $action): JsonResponse
    {
        $user = $action->execute($request->toData());

        // No token is issued here on purpose: the account must prove control of the
        // address first, and issuing a session would make the verification step
        // skippable for anything that only checks "is authenticated".
        return response()->json([
            'message' => 'Hesabınız oluşturuldu. Doğrulama e-postasını gönderdik.',
            'data' => new UserResource($user),
        ], 201);
    }

    public function login(LoginRequest $request, AuthenticateUser $action): JsonResponse
    {
        try {
            $result = $action->execute($request->toData());
        } catch (AuthenticationFailed $e) {
            throw $e->toValidationException();
        }

        return response()->json([
            'data' => [
                'token' => $result->token,
                'token_type' => 'Bearer',
                'expires_at' => $result->expiresAt?->toIso8601String(),
                'user' => new UserResource($result->user->load('profile')),
            ],
        ]);
    }

    public function logout(Request $request, AuditLogger $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $token = $user->currentAccessToken();
        $tokenId = $token?->getKey();

        if ($token !== null) {
            $token->delete();
        }

        if ($tokenId !== null) {
            $user->sessions()
                ->where('token_id', $tokenId)
                ->whereNull('ended_at')
                ->update(['ended_at' => now(), 'ended_reason' => 'logout']);
        }

        $audit->record(action: 'identity.session.logout', subject: $user, actor: $user);

        return response()->json(['message' => 'Oturum kapatıldı.']);
    }

    /**
     * Ends every session on every device. Offered separately from logout because it is
     * the action a user takes when they believe their account is compromised.
     */
    public function logoutAll(Request $request, AuditLogger $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->tokens()->delete();

        $user->sessions()
            ->whereNull('ended_at')
            ->update(['ended_at' => now(), 'ended_reason' => 'logout_all']);

        $audit->record(action: 'identity.session.logout_all', subject: $user, actor: $user);

        return response()->json(['message' => 'Tüm oturumlar kapatıldı.']);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => new UserResource($user->load('profile')),
        ]);
    }

    public function verifyEmail(Request $request, EmailVerificationService $verification, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        $user = $verification->verify((string) $validated['token']);

        if ($user === null) {
            // One message for unknown, expired and already-used tokens: telling them
            // apart would confirm which tokens exist.
            throw ValidationException::withMessages([
                'token' => ['Doğrulama bağlantısı geçersiz veya süresi dolmuş.'],
            ]);
        }

        $audit->record(action: 'identity.email.verified', subject: $user, actor: $user);

        return response()->json([
            'message' => 'E-posta adresiniz doğrulandı.',
            'data' => new UserResource($user->load('profile')),
        ]);
    }

    public function resendVerification(Request $request, EmailVerificationService $verification): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->email_verified_at !== null) {
            return response()->json(['message' => 'E-posta adresiniz zaten doğrulanmış.']);
        }

        $verification->issue($user, $request->ip());

        return response()->json(['message' => 'Doğrulama e-postası tekrar gönderildi.']);
    }

    public function forgotPassword(ForgotPasswordRequest $request, PasswordResetService $service): JsonResponse
    {
        $service->request($request->email(), $request->ip(), $request->userAgent());

        // Always the same answer, whether or not the address is registered.
        return response()->json([
            'message' => 'Bu adres kayıtlıysa parola sıfırlama bağlantısı gönderildi.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request, PasswordResetService $service, AuditLogger $audit): JsonResponse
    {
        $user = $service->reset(
            (string) $request->validated('token'),
            (string) $request->validated('password'),
        );

        if ($user === null) {
            throw ValidationException::withMessages([
                'token' => ['Sıfırlama bağlantısı geçersiz veya süresi dolmuş.'],
            ]);
        }

        $audit->record(
            action: 'identity.password.reset',
            subject: $user,
            actor: $user,
            context: ['sessions_revoked' => true],
        );

        return response()->json([
            'message' => 'Parolanız güncellendi. Lütfen tekrar giriş yapın.',
        ]);
    }
}
