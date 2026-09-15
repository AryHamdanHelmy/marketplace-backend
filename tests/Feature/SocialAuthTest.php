<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    private const GOOGLE_CLIENT_ID = '123-android.apps.googleusercontent.com';

    private const APPLE_CLIENT_ID = 'com.rapaku.app';

    private const GOOGLE_JWKS = 'https://www.googleapis.com/oauth2/v3/certs';

    private const APPLE_JWKS = 'https://appleid.apple.com/auth/keys';

    // Generating a key pair is slow, so the suite signs everything with one
    // pair, plus a second, unpublished pair to stand in for an attacker.
    private static ?array $keys = null;

    // Responses to serve for the next JWKS fetches, ahead of the default set.
    // Registering a second Http::fake would not work: the stub from setUp is
    // matched first, so staging a rotation has to go through here.
    private array $jwksQueue = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_ids' => [self::GOOGLE_CLIENT_ID],
            'services.apple.client_ids' => [self::APPLE_CLIENT_ID],
        ]);

        $servePublishedKeys = fn () => array_shift($this->jwksQueue) ?? Http::response($this->jwks());

        Http::fake([
            self::GOOGLE_JWKS => $servePublishedKeys,
            self::APPLE_JWKS => $servePublishedKeys,
        ]);
    }

    public function test_a_google_token_creates_an_account_and_returns_a_working_api_token(): void
    {
        $response = $this->postJson('/api/auth/social/google', [
            'id_token' => $this->googleToken(['email' => 'ary@gmail.com', 'name' => 'Ary Hamdan']),
        ]);

        $response->assertCreated()
            ->assertJsonPath('is_new_user', true)
            ->assertJsonPath('needs_email', false)
            ->assertJsonPath('user.email', 'ary@gmail.com')
            ->assertJsonPath('user.name', 'Ary Hamdan')
            ->assertJsonPath('user.role', 'buyer')
            ->assertJsonMissingPath('user.password');

        $user = User::where('email', 'ary@gmail.com')->sole();

        $this->assertNull($user->password);
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-sub-1',
        ]);

        $this->withToken($response->json('token'))
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.email', 'ary@gmail.com');
    }

    public function test_the_role_is_honoured_when_the_sign_in_creates_the_account(): void
    {
        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->googleToken(['email' => 'seller@gmail.com']),
            'role' => 'seller',
        ])->assertCreated()->assertJsonPath('user.role', 'seller');
    }

    public function test_an_existing_social_user_signs_in_again_without_a_second_account(): void
    {
        $token = $this->googleToken(['email' => 'ary@gmail.com']);

        $this->postJson('/api/auth/social/google', ['id_token' => $token])->assertCreated();

        $second = $this->postJson('/api/auth/social/google', ['id_token' => $token]);

        $second->assertOk()->assertJsonPath('is_new_user', false);

        $this->assertSame(1, User::count());
        $this->assertSame(1, SocialAccount::count());
    }

    public function test_a_returning_user_is_recognised_by_subject_even_after_changing_their_email(): void
    {
        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->googleToken(['email' => 'old@gmail.com']),
        ])->assertCreated();

        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->googleToken(['email' => 'new@gmail.com']),
        ])->assertOk()->assertJsonPath('is_new_user', false);

        // The account keeps the address it was created with; only the
        // provider-side snapshot follows the change.
        $this->assertSame(1, User::count());
        $this->assertDatabaseHas('social_accounts', [
            'provider_user_id' => 'google-sub-1',
            'email' => 'new@gmail.com',
        ]);
    }

    public function test_a_verified_address_links_to_the_account_that_already_owns_it(): void
    {
        $existing = User::create([
            'name' => 'Ary',
            'email' => 'ary@gmail.com',
            'password' => Hash::make('password123'),
            'role' => 'seller',
        ]);

        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->googleToken(['email' => 'ary@gmail.com', 'name' => 'Ary G']),
        ])->assertOk()
            ->assertJsonPath('is_new_user', false)
            ->assertJsonPath('user.id', $existing->id)
            ->assertJsonPath('user.role', 'seller');

        $this->assertSame(1, User::count());
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $existing->id,
            'provider' => 'google',
        ]);

        // Linking must not disturb the password they already had.
        $this->assertTrue(Hash::check('password123', $existing->fresh()->password));
    }

    public function test_an_unverified_address_never_links_to_an_existing_account(): void
    {
        $victim = User::create([
            'name' => 'Ary',
            'email' => 'ary@gmail.com',
            'password' => Hash::make('password123'),
            'role' => 'seller',
        ]);

        $response = $this->postJson('/api/auth/social/google', [
            'id_token' => $this->googleToken([
                'email' => 'ary@gmail.com',
                'email_verified' => false,
            ]),
        ]);

        $response->assertCreated()
            ->assertJsonPath('needs_email', true)
            ->assertJsonPath('is_new_user', true);

        $this->assertNotSame($victim->id, $response->json('user.id'));
        $this->assertSame('ary@gmail.com', $victim->fresh()->email);
        $this->assertStringEndsWith('@no-reply.invalid', $response->json('user.email'));
    }

    public function test_an_apple_token_without_an_email_still_signs_in_and_asks_for_one(): void
    {
        $response = $this->postJson('/api/auth/social/apple', [
            'id_token' => $this->appleToken(),
        ]);

        $response->assertCreated()->assertJsonPath('needs_email', true);

        $email = $response->json('user.email');
        $this->assertStringStartsWith('apple_', $email);
        $this->assertStringEndsWith('@no-reply.invalid', $email);

        // Signing in again lands on the same account, not a second one.
        $this->postJson('/api/auth/social/apple', ['id_token' => $this->appleToken()])
            ->assertOk()
            ->assertJsonPath('user.email', $email);

        $this->assertSame(1, User::count());
    }

    public function test_a_placeholder_account_stops_asking_once_a_real_email_is_set(): void
    {
        $token = $this->postJson('/api/auth/social/apple', [
            'id_token' => $this->appleToken(),
        ])->json('token');

        $this->withToken($token)->putJson('/api/profile', [
            'name' => 'Ary Hamdan',
            'email' => 'ary@gmail.com',
        ])->assertOk();

        $this->postJson('/api/auth/social/apple', ['id_token' => $this->appleToken()])
            ->assertOk()
            ->assertJsonPath('needs_email', false)
            ->assertJsonPath('user.email', 'ary@gmail.com');
    }

    public function test_a_token_addressed_to_another_application_is_rejected(): void
    {
        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->googleToken(['aud' => 'someone-elses-client-id']),
        ])->assertUnauthorized();

        $this->assertSame(0, User::count());
    }

    public function test_a_token_signed_by_an_unpublished_key_is_rejected(): void
    {
        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->googleToken(signWithAttackerKey: true),
        ])->assertUnauthorized();

        $this->assertSame(0, User::count());
    }

    public function test_a_token_from_the_wrong_issuer_is_rejected(): void
    {
        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->googleToken(['iss' => 'https://evil.example.com']),
        ])->assertUnauthorized();
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->googleToken([
                'iat' => time() - 7200,
                'exp' => time() - 3600,
            ]),
        ])->assertUnauthorized();
    }

    public function test_a_replayed_token_is_rejected_when_a_nonce_is_expected(): void
    {
        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->googleToken(['nonce' => 'nonce-from-an-earlier-sign-in']),
            'nonce' => 'nonce-for-this-sign-in',
        ])->assertUnauthorized();

        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->googleToken(['nonce' => 'nonce-for-this-sign-in']),
            'nonce' => 'nonce-for-this-sign-in',
        ])->assertCreated();
    }

    public function test_a_token_is_rejected_when_no_client_id_is_configured(): void
    {
        config(['services.google.client_ids' => []]);

        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->googleToken(),
        ])->assertUnauthorized();
    }

    public function test_an_unknown_provider_is_refused(): void
    {
        $this->postJson('/api/auth/social/facebook', [
            'id_token' => $this->googleToken(),
        ])->assertStatus(422);
    }

    public function test_the_id_token_is_required(): void
    {
        $this->postJson('/api/auth/social/google', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('id_token');
    }

    public function test_deleting_the_account_unlinks_the_provider_so_signing_in_starts_fresh(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@rapaku.test',
            'password' => Hash::make('password123'),
            'role' => 'admin',
        ]);

        $created = $this->postJson('/api/auth/social/google', [
            'id_token' => $this->googleToken(['email' => 'ary@gmail.com']),
        ])->json('user.id');

        $this->actingAs($admin)->deleteJson("/api/users/{$created}")->assertOk();

        $again = $this->postJson('/api/auth/social/google', [
            'id_token' => $this->googleToken(['email' => 'ary@gmail.com']),
        ]);

        $again->assertCreated()->assertJsonPath('is_new_user', true);
        $this->assertNotSame($created, $again->json('user.id'));
    }

    public function test_password_login_explains_itself_on_an_account_that_has_no_password(): void
    {
        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->googleToken(['email' => 'ary@gmail.com']),
        ])->assertCreated();

        $this->postJson('/api/auth/login', [
            'email' => 'ary@gmail.com',
            'password' => 'anything',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'This account signs in with Google. Use that button, or set a password through Forgot password.');
    }

    public function test_a_social_user_can_set_a_first_password_without_a_current_one(): void
    {
        $token = $this->postJson('/api/auth/social/google', [
            'id_token' => $this->googleToken(['email' => 'ary@gmail.com']),
        ])->json('token');

        $this->withToken($token)->putJson('/api/profile/password', [
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => 'ary@gmail.com',
            'password' => 'new-password',
        ])->assertOk();
    }

    public function test_a_password_user_still_has_to_prove_the_current_one(): void
    {
        $user = User::create([
            'name' => 'Ary',
            'email' => 'ary@gmail.com',
            'password' => Hash::make('password123'),
            'role' => 'buyer',
        ]);

        $this->actingAs($user)->putJson('/api/profile/password', [
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('current_password');
    }

    public function test_a_second_google_account_on_the_same_address_repoints_the_link(): void
    {
        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->googleToken(['sub' => 'google-sub-1', 'email' => 'ary@gmail.com']),
        ])->assertCreated();

        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->googleToken(['sub' => 'google-sub-2', 'email' => 'ary@gmail.com']),
        ])->assertOk()->assertJsonPath('is_new_user', false);

        $this->assertSame(1, User::count());
        $this->assertSame(1, SocialAccount::count());
        $this->assertDatabaseHas('social_accounts', ['provider_user_id' => 'google-sub-2']);
    }

    public function test_a_key_we_have_not_cached_yet_is_fetched_before_giving_up(): void
    {
        // The provider has rotated: the first fetch only has the old key,
        // and the token in hand is signed with the new one.
        $this->jwksQueue = [
            Http::response($this->jwks($this->keys()['attacker']['public'], 'retired-key')),
        ];

        $this->postJson('/api/auth/social/google', [
            'id_token' => $this->googleToken(['email' => 'ary@gmail.com']),
        ])->assertCreated();

        Http::assertSentCount(2);
    }

    // --- token plumbing ---------------------------------------------------

    private function googleToken(array $claims = [], bool $signWithAttackerKey = false): string
    {
        return $this->token(array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => self::GOOGLE_CLIENT_ID,
            'sub' => 'google-sub-1',
            'email' => 'ary@gmail.com',
            'email_verified' => true,
        ], $claims), $signWithAttackerKey);
    }

    private function appleToken(array $claims = []): string
    {
        return $this->token(array_merge([
            'iss' => 'https://appleid.apple.com',
            'aud' => self::APPLE_CLIENT_ID,
            'sub' => 'apple-sub-1',
        ], $claims));
    }

    private function token(array $claims, bool $signWithAttackerKey = false): string
    {
        $claims += ['iat' => time(), 'exp' => time() + 3600];

        $keys = $this->keys();

        return JWT::encode(
            $claims,
            $signWithAttackerKey ? $keys['attacker']['private'] : $keys['private'],
            'RS256',
            'test-key-1',
        );
    }

    /** The public half of a signing key, in the shape a provider serves it. */
    private function jwks(?string $publicKey = null, string $kid = 'test-key-1'): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_public($publicKey ?? $this->keys()['public']));

        return [
            'keys' => [[
                'kty' => 'RSA',
                'alg' => 'RS256',
                'use' => 'sig',
                'kid' => $kid,
                'n' => $this->base64Url($details['rsa']['n']),
                'e' => $this->base64Url($details['rsa']['e']),
            ]],
        ];
    }

    private function keys(): array
    {
        if (self::$keys === null) {
            self::$keys = [
                ...$this->generateKeyPair(),
                'attacker' => $this->generateKeyPair(),
            ];
        }

        return self::$keys;
    }

    private function generateKeyPair(): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($key, $private);

        return [
            'private' => $private,
            'public' => openssl_pkey_get_details($key)['key'],
        ];
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
