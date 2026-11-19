<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function spa(): static
    {
        return $this->withHeaders(['Origin' => 'http://localhost', 'Referer' => 'http://localhost']);
    }

    public function test_login_berhasil_mengembalikan_profil_dan_mencatat_last_login(): void
    {
        $u = User::factory()->create(['email' => 'budi@contoh.id', 'password' => 'rahasia123']);

        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'budi@contoh.id', 'password' => 'rahasia123'])
            ->assertOk()->assertJsonPath('data.email', 'budi@contoh.id')->assertJsonStructure(['data' => ['public_id', 'companies']]);

        $this->assertNotNull($u->fresh()->last_login);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $u->id, 'aksi' => 'login']);
    }

    public function test_sandi_salah_ditolak_tanpa_membocorkan_penyebab(): void
    {
        User::factory()->create(['email' => 'budi@contoh.id', 'password' => 'rahasia123']);

        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'budi@contoh.id', 'password' => 'salah'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_akun_ditangguhkan_tidak_bisa_masuk(): void
    {
        User::factory()->create(['email' => 'x@contoh.id', 'password' => 'rahasia123', 'status' => 'suspended']);

        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'x@contoh.id', 'password' => 'rahasia123'])
            ->assertStatus(422);
    }

    public function test_percobaan_login_dibatasi(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->spa()->postJson('/api/v1/auth/login', ['email' => 'a@contoh.id', 'password' => 'x'])->assertStatus(422);
        }
        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'a@contoh.id', 'password' => 'x'])->assertStatus(429);
    }

    public function test_me_butuh_login_dan_logout_mengakhiri_sesi(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();

        User::factory()->create(['email' => 'budi@contoh.id', 'password' => 'rahasia123']);
        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'budi@contoh.id', 'password' => 'rahasia123'])->assertOk();
        $this->spa()->getJson('/api/v1/auth/me')->assertOk();
        $this->spa()->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->spa()->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
