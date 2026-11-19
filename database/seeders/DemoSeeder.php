<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Platform\Actions\CreateCompany;
use App\Modules\Platform\Models\CompanyUser;
use Illuminate\Database\Seeder;

/**
 * Data uji lokal untuk mencoba UI: satu user (budi@contoh.id / rahasia123) dan satu perusahaan (dia jadi Owner).
 * Jalankan: php artisan db:seed --class=DemoSeeder. Aman diulang. Ditolak di production.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DemoSeeder tidak boleh dijalankan di production.');

            return;
        }

        $this->call(RbacSeeder::class); // CreateCompany butuh role Owner bawaan

        $user = User::firstOrCreate(
            ['email' => 'budi@contoh.id'],
            ['name' => 'Budi Santoso', 'password' => 'rahasia123', 'status' => 'active'],
        );

        if (! CompanyUser::query()->where('user_id', $user->getKey())->exists()) {
            app(CreateCompany::class)->handle($user, [
                'nama' => 'PT Maju Jaya',
                'npwp' => '09.123.456.7-411.000',
                'is_pkp' => true,
                'tanggal_mulai_pembukuan' => now()->startOfYear()->toDateString(),
                'template' => 'dagang',
            ]);
        }
    }
}
