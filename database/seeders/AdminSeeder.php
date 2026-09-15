<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Buat akun admin pertama dari environment, bukan dari kredensial tetap.
     *
     * Versi sebelumnya menanam email dan password admin tetap langsung di
     * file ini. Nilai itu ikut terbawa ke git history dan berlaku di setiap
     * environment yang pernah menjalankan db:seed — artinya siapa pun yang
     * bisa membaca repo ini memegang akun admin penuh.
     *
     * Sekarang seeder menolak berjalan tanpa ADMIN_EMAIL dan ADMIN_PASSWORD.
     * Melewatkan seeder lebih baik daripada diam-diam membuat pintu masuk
     * yang passwordnya diketahui orang lain.
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (!$email || !$password) {
            $this->command?->warn(
                'AdminSeeder dilewati: set ADMIN_EMAIL dan ADMIN_PASSWORD dulu.'
            );

            return;
        }

        if (mb_strlen($password) < 12) {
            $this->command?->error(
                'AdminSeeder dilewati: ADMIN_PASSWORD minimal 12 karakter.'
            );

            return;
        }

        // firstOrCreate, bukan updateOrCreate: kalau akun admin sudah ada,
        // passwordnya tidak ditimpa oleh isi env. Deploy ulang tidak boleh
        // diam-diam mengembalikan password lama yang mungkin sudah dirotasi.
        $admin = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => env('ADMIN_NAME', 'Admin'),
                'password' => Hash::make($password),
                'role' => 'admin',
            ]
        );

        $this->command?->info(
            $admin->wasRecentlyCreated
                ? "Akun admin dibuat untuk {$email}."
                : "Akun admin {$email} sudah ada — tidak diubah."
        );
    }
}
