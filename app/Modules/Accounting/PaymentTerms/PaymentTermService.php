<?php

namespace App\Modules\Accounting\PaymentTerms;

use App\Models\User;
use App\Modules\Platform\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** UC-10 (Syarat Pembayaran). */
class PaymentTermService
{
    public function __construct(private AuditLogger $audit)
    {
    }

    public function create(array $data, ?User $user = null): PaymentTerm
    {
        $row = $this->normalize($data);
        $this->assertRules($row);

        if (PaymentTerm::query()->where('nama', $row['nama'])->exists()) {
            throw new PaymentTermException("Syarat pembayaran '{$row['nama']}' sudah ada.");
        }

        return DB::transaction(function () use ($row) {
            $term = PaymentTerm::create($row + ['is_aktif' => true]);
            $this->audit->log('payment_term', (int) $term->getKey(), 'create', null, $row);

            return $term;
        });
    }

    public function update(PaymentTerm $term, array $data, ?User $user = null): PaymentTerm
    {
        $merged = $this->normalize($data + $term->only(['nama', 'jumlah_hari', 'diskon_persen', 'diskon_hari']));
        $this->assertRules($merged);
        $term->fill($merged + (array_key_exists('is_aktif', $data) ? ['is_aktif' => (bool) $data['is_aktif']] : []));
        $dirty = $term->getDirty();

        if (! $dirty) {
            return $term;
        }

        if (isset($dirty['nama']) && PaymentTerm::query()->where('nama', $dirty['nama'])->where('id', '!=', $term->getKey())->exists()) {
            throw new PaymentTermException("Syarat pembayaran '{$dirty['nama']}' sudah ada.");
        }

        return DB::transaction(function () use ($term, $dirty) {
            $before = array_intersect_key($term->getOriginal(), $dirty);
            $term->save();
            $this->audit->log('payment_term', (int) $term->getKey(), 'update', $before, $dirty);

            return $term->fresh();
        });
    }

    public function delete(PaymentTerm $term): void
    {
        DB::transaction(function () use ($term) {
            try {
                $term->delete();
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) === '23000') {
                    throw new PaymentTermException('Syarat pembayaran sudah dipakai pelanggan/pemasok/dokumen; nonaktifkan saja.');
                }
                throw $e;
            }
            $this->audit->log('payment_term', (int) $term->getKey(), 'delete', $term->only(['nama', 'jumlah_hari']), null);
        });
    }

    private function normalize(array $d): array
    {
        return [
            'nama' => trim((string) $d['nama']),
            'jumlah_hari' => (int) ($d['jumlah_hari'] ?? 0),
            'diskon_persen' => (string) ($d['diskon_persen'] ?? '0'),
            'diskon_hari' => (int) ($d['diskon_hari'] ?? 0),
        ];
    }

    private function assertRules(array $r): void
    {
        if ($r['nama'] === '') {
            throw new PaymentTermException('Nama syarat pembayaran wajib diisi.');
        }
        if (! is_numeric($r['diskon_persen']) || bccomp($r['diskon_persen'], '0', 4) < 0 || bccomp($r['diskon_persen'], '100', 4) > 0) {
            throw new PaymentTermException('Diskon pelunasan harus antara 0 dan 100 persen.');
        }
        if (bccomp($r['diskon_persen'], '0', 4) > 0 && ($r['diskon_hari'] <= 0 || $r['diskon_hari'] > $r['jumlah_hari'])) {
            throw new PaymentTermException('Hari diskon harus lebih dari 0 dan tidak melebihi jumlah hari jatuh tempo.');
        }
        if (bccomp($r['diskon_persen'], '0', 4) === 0 && $r['diskon_hari'] > 0) {
            throw new PaymentTermException('Hari diskon hanya boleh diisi bila ada persen diskon pelunasan.');
        }
    }
}
