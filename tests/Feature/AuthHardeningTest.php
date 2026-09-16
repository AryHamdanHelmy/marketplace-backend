<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login:korban@rapaku.test');
    }

    public function test_brute_force_satu_akun_terhenti_walau_ip_berganti(): void
    {
        User::factory()->create([
            'email' => 'korban@rapaku.test',
            'password' => Hash::make('rahasia-sekali'),
        ]);

        // Setiap percobaan datang dari IP berbeda, persis seperti serangan
        // yang tersebar. Throttle route yang terikat IP tidak akan menahannya.
        $terakhir = null;

        for ($i = 0; $i < 21; $i++) {
            $terakhir = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.' . $i])
                ->postJson('/api/auth/login', [
                    'email' => 'korban@rapaku.test',
                    'password' => 'tebakan-salah',
                ]);
        }

        $terakhir->assertStatus(429);

        // Bahkan dengan password yang benar dan IP yang belum pernah dipakai,
        // akun itu terkunci sampai jendelanya lewat.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->postJson('/api/auth/login', [
                'email' => 'korban@rapaku.test',
                'password' => 'rahasia-sekali',
            ])->assertStatus(429);
    }

    public function test_login_berhasil_membersihkan_hitungan_percobaan(): void
    {
        User::factory()->create([
            'email' => 'korban@rapaku.test',
            'password' => Hash::make('rahasia-sekali'),
        ]);

        // Salah ketik beberapa kali, lalu berhasil.
        foreach (range(1, 3) as $i) {
            $this->postJson('/api/auth/login', [
                'email' => 'korban@rapaku.test',
                'password' => 'salah',
            ])->assertStatus(401);
        }

        $this->postJson('/api/auth/login', [
            'email' => 'korban@rapaku.test',
            'password' => 'rahasia-sekali',
        ])->assertOk();

        // Hitungannya bersih: percobaan berikutnya tidak langsung terkunci.
        $this->postJson('/api/auth/login', [
            'email' => 'korban@rapaku.test',
            'password' => 'salah',
        ])->assertStatus(401);
    }

    public function test_token_sanctum_punya_masa_berlaku(): void
    {
        $this->assertNotNull(
            config('sanctum.expiration'),
            'Token tanpa masa berlaku berlaku selamanya kalau bocor.'
        );
    }
}
