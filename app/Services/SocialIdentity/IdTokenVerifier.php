<?php

namespace App\Services\SocialIdentity;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;
use UnexpectedValueException;

// Checks an OpenID Connect id_token the client obtained from Google or Apple.
//
// The whole point of this class is that we trust the provider's signature and
// nothing else. A client that hands us a profile blob, or an access_token we
// would have to go ask about, can hand us someone else's email just as
// easily; a token signed by Google's key and addressed to our client id
// cannot be produced by anyone but Google.
class IdTokenVerifier
{
    private const PROVIDERS = [
        'google' => [
            'jwks' => 'https://www.googleapis.com/oauth2/v3/certs',
            // Google has issued both spellings over the years and still
            // documents both as valid.
            'issuers' => ['https://accounts.google.com', 'accounts.google.com'],
        ],
        'apple' => [
            'jwks' => 'https://appleid.apple.com/auth/keys',
            'issuers' => ['https://appleid.apple.com'],
        ],
    ];

    // Providers publish key rotations well ahead of using them, so a cached
    // set stays valid for a long time. A token signed with a key we have not
    // seen refetches immediately regardless, so this is not a ceiling on how
    // fast we pick up a new key.
    private const JWKS_TTL_HOURS = 12;

    // Clock skew between our server and the provider's, in seconds.
    private const LEEWAY = 60;

    public static function supports(string $provider): bool
    {
        return array_key_exists($provider, self::PROVIDERS);
    }

    public static function providers(): array
    {
        return array_keys(self::PROVIDERS);
    }

    /**
     * @param  string|null  $expectedNonce  When the client sent a nonce with its
     *                                      authorization request, pass it here:
     *                                      it is what stops a token captured
     *                                      from one sign-in being replayed into
     *                                      another.
     *
     * @throws InvalidIdTokenException
     */
    public function verify(string $provider, string $idToken, ?string $expectedNonce = null): SocialIdentity
    {
        if (! self::supports($provider)) {
            throw new InvalidIdTokenException("Unsupported provider [{$provider}].");
        }

        $audiences = $this->audiences($provider);

        $claims = $this->decode($provider, $idToken);

        $this->assertIssuer($provider, $claims);
        $this->assertAudience($claims, $audiences);
        $this->assertNonce($claims, $expectedNonce);

        $subject = isset($claims->sub) ? trim((string) $claims->sub) : '';

        if ($subject === '') {
            throw new InvalidIdTokenException('Token carries no subject.');
        }

        $email = isset($claims->email) ? trim((string) $claims->email) : '';

        return new SocialIdentity(
            provider: $provider,
            subject: $subject,
            email: $email !== '' ? $email : null,
            // Apple sends this claim as the string "true"; Google sends a
            // real boolean. Anything else counts as unverified.
            emailVerified: $email !== '' && in_array($claims->email_verified ?? false, [true, 'true'], true),
            name: $this->name($claims),
        );
    }

    private function decode(string $provider, string $idToken): object
    {
        JWT::$leeway = self::LEEWAY;

        try {
            return JWT::decode($idToken, JWK::parseKeySet($this->jwks($provider)));
        } catch (UnexpectedValueException $e) {
            // Most likely the provider signed with a key added since we
            // cached the set. Refetch once before giving up — but only for
            // this class of error, so a plainly malformed token does not turn
            // every request into an outbound call.
            if (! str_contains($e->getMessage(), 'kid')) {
                throw new InvalidIdTokenException($e->getMessage(), previous: $e);
            }
        } catch (Throwable $e) {
            throw new InvalidIdTokenException($e->getMessage(), previous: $e);
        }

        try {
            return JWT::decode($idToken, JWK::parseKeySet($this->jwks($provider, fresh: true)));
        } catch (Throwable $e) {
            throw new InvalidIdTokenException($e->getMessage(), previous: $e);
        }
    }

    private function assertIssuer(string $provider, object $claims): void
    {
        $issuer = isset($claims->iss) ? (string) $claims->iss : '';

        if (! in_array($issuer, self::PROVIDERS[$provider]['issuers'], true)) {
            throw new InvalidIdTokenException("Unexpected issuer [{$issuer}].");
        }
    }

    // A token is only ours if it was minted for one of our client ids.
    // Without this check any app's Google token would sign its holder in
    // here, which is the classic way this integration gets broken into.
    private function assertAudience(object $claims, array $audiences): void
    {
        $tokenAudiences = array_map('strval', (array) ($claims->aud ?? []));

        if (array_intersect($tokenAudiences, $audiences) === []) {
            throw new InvalidIdTokenException('Token was not issued for this application.');
        }
    }

    private function assertNonce(object $claims, ?string $expectedNonce): void
    {
        if ($expectedNonce === null || $expectedNonce === '') {
            return;
        }

        $nonce = isset($claims->nonce) ? (string) $claims->nonce : '';

        if (! hash_equals($expectedNonce, $nonce)) {
            throw new InvalidIdTokenException('Nonce does not match.');
        }
    }

    private function name(object $claims): ?string
    {
        // Apple omits the name from the token entirely; Google sends "name",
        // and falls back to the parts when the profile has no display name.
        $name = trim((string) ($claims->name ?? ''));

        if ($name === '') {
            $name = trim(
                trim((string) ($claims->given_name ?? '')).' '.trim((string) ($claims->family_name ?? ''))
            );
        }

        return $name !== '' ? mb_substr($name, 0, 100) : null;
    }

    /** @return array<int, string> */
    private function audiences(string $provider): array
    {
        $configured = config("services.{$provider}.client_ids", []);

        $audiences = array_values(array_filter(array_map(
            static fn ($id) => trim((string) $id),
            is_array($configured) ? $configured : [$configured],
        ), static fn (string $id) => $id !== ''));

        if ($audiences === []) {
            // Refusing here is deliberate: with no configured audience the
            // check above would have nothing to compare against and would
            // wave every token through.
            throw new InvalidIdTokenException(
                "No client ids configured for [{$provider}]; set ".strtoupper($provider).'_CLIENT_IDS.'
            );
        }

        return $audiences;
    }

    private function jwks(string $provider, bool $fresh = false): array
    {
        $key = "social_identity:jwks:{$provider}";

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember(
            $key,
            now()->addHours(self::JWKS_TTL_HOURS),
            fn () => Http::timeout(5)
                ->retry(2, 200, throw: false)
                ->get(self::PROVIDERS[$provider]['jwks'])
                ->throw()
                ->json()
        );
    }
}
