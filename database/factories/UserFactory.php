<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * Tabel users di proyek ini tidak punya email_verified_at maupun
     * remember_token — keduanya dibuang di migrasi awal. Factory bawaan
     * Laravel masih mengisinya, jadi setiap User::factory() gagal dengan
     * "table users has no column named email_verified_at". Itu sebabnya
     * belum ada satu pun feature test yang bisa jalan di repo ini.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => 'buyer',
        ];
    }

    public function seller(): static
    {
        return $this->state(fn (array $attributes) => ['role' => 'seller']);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => 'admin']);
    }
}
