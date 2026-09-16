<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class EmailEnumerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_check_email_sudah_tidak_ada(): void
    {
        User::factory()->create(['email' => 'terdaftar@rapaku.test']);

        $this->postJson('/api/auth/check-email', ['email' => 'terdaftar@rapaku.test'])
            ->assertStatus(404);
    }

    public function test_login_menjawab_sama_untuk_email_asing_dan_password_salah(): void
    {
        User::factory()->create([
            'email' => 'terdaftar@rapaku.test',
            'password' => Hash::make('rahasia-sekali'),
        ]);

        $passwordSalah = $this->postJson('/api/auth/login', [
            'email' => 'terdaftar@rapaku.test',
            'password' => 'bukan-ini',
        ]);

        RateLimiter::clear('login:terdaftar@rapaku.test');

        $emailAsing = $this->postJson('/api/auth/login', [
            'email' => 'tidak-ada@rapaku.test',
            'password' => 'bukan-ini',
        ]);

        $this->assertSame($passwordSalah->status(), $emailAsing->status());
        $this->assertSame(401, $emailAsing->status());
        $this->assertSame(
            $passwordSalah->json(),
            $emailAsing->json(),
            'Badan respons harus identik — perbedaan sekecil apa pun membocorkan alamat mana yang terdaftar.'
        );
    }

    public function test_waktu_respons_kedua_cabang_tidak_berbeda_mencolok(): void
    {
        // phpunit.xml memakai BCRYPT_ROUNDS=4 supaya suite cepat. Dengan biaya
        // serendah itu, bcrypt tenggelam di bawah overhead request dan test ini
        // akan lulus bahkan tanpa hash tanding — alias tidak menguji apa pun.
        // Cost dinaikkan khusus di sini supaya yang diukur benar-benar
        // pekerjaan hashing-nya.
        config(['hashing.bcrypt.rounds' => 10]);

        User::factory()->create([
            'email' => 'terdaftar@rapaku.test',
            'password' => Hash::make('rahasia-sekali'),
        ]);

        $ukur = function (string $email): float {
            $mulai = microtime(true);

            $this->postJson('/api/auth/login', [
                'email' => $email,
                'password' => 'bukan-ini',
            ]);

            $selesai = microtime(true) - $mulai;

            RateLimiter::clear('login:' . $email);
            RateLimiter::clear('login:terdaftar@rapaku.test');

            return $selesai;
        };

        // Pemanasan: sekalian memaksa hash tanding dihitung, supaya biaya
        // sekali-seumur-proses itu tidak masuk ke pengukuran.
        $ukur('terdaftar@rapaku.test');
        $ukur('tidak-ada@rapaku.test');

        $adaAkun = 0.0;
        $tanpaAkun = 0.0;

        for ($i = 0; $i < 5; $i++) {
            $adaAkun += $ukur('terdaftar@rapaku.test');
            $tanpaAkun += $ukur('tidak-ada@rapaku.test');
        }

        // Tanpa hash tanding, cabang "email asing" keluar tanpa menghitung
        // bcrypt sama sekali. Ambang 0.5 longgar karena ini pengukuran waktu:
        // yang dijaga adalah selisih ordo besaran, bukan presisi.
        $this->assertGreaterThan(
            $adaAkun * 0.5,
            $tanpaAkun,
            'Cabang email tidak terdaftar menjawab terlalu cepat — selisih waktunya membocorkan keberadaan akun.'
        );
    }

    public function test_registrasi_melaporkan_email_duplikat_lewat_validasi(): void
    {
        User::factory()->create(['email' => 'terdaftar@rapaku.test']);

        $this->postJson('/api/auth/register', [
            'name' => 'Orang Baru',
            'email' => 'terdaftar@rapaku.test',
            'password' => 'password-baru',
            'password_confirmation' => 'password-baru',
            'role' => 'buyer',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_registrasi_dengan_email_baru_tetap_berhasil(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Orang Baru',
            'email' => 'baru@rapaku.test',
            'password' => 'password-baru',
            'password_confirmation' => 'password-baru',
            'role' => 'buyer',
        ])->assertCreated();
    }
}
