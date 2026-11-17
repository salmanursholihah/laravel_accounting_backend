<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Idempotent: aman dijalankan ulang (katalog permission, 6 role bawaan, dan hak masing-masing). */
class RbacSeeder extends Seeder
{
    public function run(): void
    {
        $cfg = config('rbac');
        $now = now();

        // 1. Katalog permission
        $catalog = [];
        foreach ($cfg['modules'] as $features) {
            foreach ($features as $fitur) {
                foreach ($this->actionsOf($fitur, 'F', $cfg) as $aksi) {
                    $catalog[$fitur . ':' . $aksi] = ['fitur' => $fitur, 'aksi' => $aksi];
                }
            }
        }
        foreach ($cfg['khusus'] as $aksi) {
            $catalog['khusus:' . $aksi] = ['fitur' => 'khusus', 'aksi' => $aksi];
        }
        foreach (array_chunk(array_values($catalog), 500) as $chunk) {
            DB::table('permissions')->insertOrIgnore($chunk);
        }
        $permId = DB::table('permissions')->get()->mapWithKeys(fn ($p) => [$p->fitur . ':' . $p->aksi => $p->id])->all();

        // 2. Role bawaan dan hak-haknya
        foreach ($cfg['matrix'] as $roleName => $matrix) {
            $role = DB::table('roles')->whereNull('company_id')->where('nama', $roleName)->first();
            $roleId = $role?->id ?? DB::table('roles')->insertGetId([
                'company_id' => null, 'nama' => $roleName, 'is_sistem' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $own = in_array($roleName, $cfg['post_hanya_miliknya'], true);
            $grants = []; // "fitur:aksi" => [hanya_miliknya, nilai_batas]

            foreach ($cfg['modules'] as $module => $features) {
                $spec = $matrix[$module] ?? ($matrix['*'] ?? null);
                if ($spec === null) {
                    continue;
                }
                foreach ($features as $fitur) {
                    $level = is_array($spec) ? ($spec[$fitur] ?? null) : $spec;
                    if ($level === null) {
                        continue;
                    }
                    foreach ($this->actionsOf($fitur, $level, $cfg) as $aksi) {
                        $grants[$fitur . ':' . $aksi] = [$aksi === 'post' && $own ? 1 : 0, null];
                    }
                }
            }

            $special = $cfg['khusus_role'][$roleName] ?? [];
            if ($special === '*') {
                $special = array_fill_keys($cfg['khusus'], null);
            }
            foreach ($special as $aksi => $batas) {
                $grants['khusus:' . $aksi] = [0, $batas];
            }

            $rows = [];
            foreach ($grants as $key => [$hanyaMiliknya, $batas]) {
                $rows[] = [
                    'role_id' => $roleId,
                    'permission_id' => $permId[$key],
                    'hanya_miliknya' => $hanyaMiliknya,
                    'nilai_batas' => $batas,
                ];
            }

            DB::transaction(function () use ($roleId, $rows) {
                DB::table('role_permissions')->where('role_id', $roleId)->delete();
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('role_permissions')->insert($chunk);
                }
            });
        }
    }

    /** Aksi yang tersedia untuk sebuah fitur pada level F/C/V. */
    private function actionsOf(string $fitur, string $level, array $cfg): array
    {
        if ($fitur === 'dashboard') {
            return ['view'];
        }

        $isDoc = in_array($fitur, $cfg['dokumen_posting'], true);

        return match ($level) {
            'F' => array_merge($cfg['aksi_dasar'], $isDoc ? ['post', 'void'] : []),
            'C' => array_merge(['view', 'create', 'update', 'delete', 'print'], $isDoc ? ['post'] : []),
            'V' => ['view', 'print', 'export'],
            default => [],
        };
    }
}
