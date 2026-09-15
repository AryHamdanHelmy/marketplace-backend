<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReleasesEmails;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SocialIdentity\IdTokenVerifier;
use App\Services\SocialIdentity\InvalidIdTokenException;
use App\Services\SocialIdentity\SocialIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

// Signs someone in with an id_token their app already obtained from Google or
// Apple, and hands back the same Sanctum token the password login issues, so
// nothing downstream has to know which way they came in.
class SocialAuthController extends Controller
{
    use ReleasesEmails;

    // ".invalid" is reserved by RFC 2606 precisely so that it can never
    // resolve: nothing we send to a placeholder can escape to a real inbox.
    private const PLACEHOLDER_DOMAIN = 'no-reply.invalid';

    public function __construct(private readonly IdTokenVerifier $verifier) {}

    // POST /api/auth/social/{provider}
    public function store(Request $request, string $provider)
    {
        $provider = strtolower($provider);

        if (! IdTokenVerifier::supports($provider)) {
            return response()->json([
                'success' => false,
                'message' => "We don't support signing in with {$provider}.",
            ], 422);
        }

        $validated = $request->validate([
            'id_token' => 'required|string',
            'nonce' => 'nullable|string|max:255',
            // Only read when the sign-in creates the account. An existing
            // buyer cannot turn themselves into a seller by passing a role.
            'role' => ['nullable', Rule::in(['buyer', 'seller'])],
        ]);

        try {
            $identity = $this->verifier->verify(
                $provider,
                $validated['id_token'],
                $validated['nonce'] ?? null,
            );
        } catch (InvalidIdTokenException $e) {
            // The reason goes to the log, not to the caller.
            Log::warning('Rejected social id_token', [
                'provider' => $provider,
                'reason' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => "We couldn't verify that sign-in. Please try again.",
            ], 401);
        }

        [$user, $isNewUser] = $this->resolveUser($identity, $validated['role'] ?? null);

        $token = $user->createToken("{$provider}_auth_token")->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
            'is_new_user' => $isNewUser,
            // True when the provider told us nothing usable to reach this
            // person at, so we parked a placeholder address on the account.
            // The app should ask for a real email and PUT /api/profile.
            'needs_email' => $this->isPlaceholderEmail($user->email),
        ], $isNewUser ? 201 : 200);
    }

    /** @return array{0: User, 1: bool} */
    private function resolveUser(SocialIdentity $identity, ?string $role): array
    {
        return DB::transaction(function () use ($identity, $role) {
            $account = SocialAccount::where('provider', $identity->provider)
                ->where('provider_user_id', $identity->subject)
                ->lockForUpdate()
                ->first();

            if ($account) {
                $user = $account->user;

                if ($user) {
                    $account->update([
                        'email' => $identity->email,
                        'name' => $identity->name,
                        'last_login_at' => now(),
                    ]);

                    return [$user, false];
                }

                // The account this was attached to has since been deleted.
                // Signing in again starts a genuinely new account rather than
                // reviving one the person asked us to remove.
                $account->delete();
            }

            [$user, $isNewUser] = $this->findOrCreateUser($identity, $role);

            // updateOrCreate, not create: the account may already have a
            // link for this provider if the person authorised us with one
            // Google account and later with another that carries the same
            // verified address. Re-pointing the link beats a unique
            // constraint violation surfacing as a 500.
            SocialAccount::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'provider' => $identity->provider,
                ],
                [
                    'provider_user_id' => $identity->subject,
                    'email' => $identity->email,
                    'name' => $identity->name,
                    'last_login_at' => now(),
                ]
            );

            return [$user, $isNewUser];
        });
    }

    /** @return array{0: User, 1: bool} */
    private function findOrCreateUser(SocialIdentity $identity, ?string $role): array
    {
        $email = $identity->trustedEmail();

        // Someone who registered with a password and later taps "Sign in with
        // Google" on the same verified address is the same person, so the
        // provider gets linked to the account they already have.
        if ($email !== null) {
            $existing = User::where('email', $email)->lockForUpdate()->first();

            if ($existing) {
                return [$existing, false];
            }
        }

        // No verified address to go on — Apple in particular will stop
        // sending one once the person has authorised us. Park a placeholder
        // so the sign-in still works, and tell the app to collect a real one.
        $email ??= $this->placeholderEmail($identity);

        // A deleted account may still be holding this address. Free it, the
        // same way registration does, then start a new account. The old row
        // keeps its history and is never revived.
        $trashed = User::onlyTrashed()
            ->where('email', $email)
            ->lockForUpdate()
            ->first();

        if ($trashed) {
            $trashed->email = $this->releasedEmail($trashed->id, $trashed->email);
            $trashed->saveQuietly();
        }

        $user = User::create([
            'name' => $identity->name ?: Str::before($email, '@'),
            'email' => $email,
            // No password of ours to store. They sign in through the provider
            // until they set one via the password reset flow.
            'password' => null,
            'role' => $role ?? 'buyer',
        ]);

        return [$user, true];
    }

    // Derived from the provider's subject so the same person always lands on
    // the same placeholder, which keeps the unique index meaningful if they
    // delete the account and sign in again.
    private function placeholderEmail(SocialIdentity $identity): string
    {
        $hash = substr(hash('sha256', $identity->provider.':'.$identity->subject), 0, 32);

        return "{$identity->provider}_{$hash}@".self::PLACEHOLDER_DOMAIN;
    }

    private function isPlaceholderEmail(string $email): bool
    {
        return str_ends_with($email, '@'.self::PLACEHOLDER_DOMAIN);
    }
}
