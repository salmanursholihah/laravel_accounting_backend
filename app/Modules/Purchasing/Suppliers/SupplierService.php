<?php

namespace App\Modules\Purchasing\Suppliers;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\PaymentTerms\PaymentTerm;
use App\Modules\Accounting\Support\Money;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Tenancy\CurrentCompany;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\PurchasingException;
use Illuminate\Support\Facades\DB;

/**
 * Master Pemasok (UC-38). Pemasok = ekstensi 1:1 dari contacts (satu pihak bisa jadi pelanggan sekaligus pemasok tanpa data ganda).
 * 'contact_id' yang sudah ada dapat dijadikan pemasok; bila tidak, kontak baru dibuat (kode, nama wajib).
 */
class SupplierService
{
    private const CONTACT = ['kode', 'nama', 'alamat', 'kota', 'telepon', 'email', 'npwp'];
    private const EXT = ['supplier_category_id', 'payment_term_id', 'akun_utang_override_id', 'no_rekening'];

    public function __construct(private AuditLogger $audit, private CurrentCompany $current)
    {
    }

    public function create(array $d, User $user): array
    {
        $this->assertExt($d);

        return DB::transaction(function () use ($d, $user) {
            $cid = $this->current->id();
            $now = now();

            if (! empty($d['contact_id'])) {
                $contact = DB::table('contacts')->where('company_id', $cid)->where('id', $d['contact_id'])->lockForUpdate()->first()
                    ?? throw PurchasingException::notFound('Kontak');
                if (DB::table('suppliers')->where('company_id', $cid)->where('contact_id', $contact->id)->exists()) {
                    throw new PurchasingException('Kontak ini sudah menjadi pemasok.', 'supplier_exists');
                }
                DB::table('contacts')->where('id', $contact->id)->update(['is_pemasok' => true, 'updated_by' => $user->getKey(), 'updated_at' => $now]);
                $contactId = (int) $contact->id;
            } else {
                foreach (['kode', 'nama'] as $f) {
                    if (empty(trim((string) ($d[$f] ?? '')))) {
                        throw new PurchasingException("Field {$f} wajib diisi.", 'required_field');
                    }
                }
                if (DB::table('contacts')->where('company_id', $cid)->where('kode', trim($d['kode']))->exists()) {
                    throw new PurchasingException("Kode kontak '{$d['kode']}' sudah dipakai.", 'duplicate_code');
                }
                $contactId = (int) DB::table('contacts')->insertGetId(array_intersect_key($d, array_flip(self::CONTACT)) + [
                    'company_id' => $cid, 'is_pemasok' => true, 'is_aktif' => true,
                    'created_by' => $user->getKey(), 'created_at' => $now, 'updated_by' => $user->getKey(), 'updated_at' => $now,
                ]);
            }

            $s = Supplier::create(array_intersect_key($d, array_flip(self::EXT)) + [
                'contact_id' => $contactId, 'created_by' => $user->getKey(), 'updated_by' => $user->getKey(),
            ]);
            $this->audit->log('supplier', (int) $s->getKey(), 'create', null, ['contact_id' => $contactId] + array_intersect_key($d, array_flip(self::EXT)));

            return $this->present($s);
        });
    }

    public function update(int $id, array $d, User $user): array
    {
        $this->assertExt($d);

        return DB::transaction(function () use ($id, $d, $user) {
            $s = Supplier::query()->lockForUpdate()->find($id) ?? throw PurchasingException::notFound('Pemasok');
            $cid = $this->current->id();

            $contact = array_intersect_key($d, array_flip(self::CONTACT));
            if (isset($contact['kode']) && DB::table('contacts')->where('company_id', $cid)->where('kode', $contact['kode'])->where('id', '!=', $s->contact_id)->exists()) {
                throw new PurchasingException("Kode kontak '{$contact['kode']}' sudah dipakai.", 'duplicate_code');
            }
            if ($contact) {
                DB::table('contacts')->where('company_id', $cid)->where('id', $s->contact_id)->update($contact + ['updated_by' => $user->getKey(), 'updated_at' => now()]);
            }
            if (array_key_exists('is_aktif', $d)) {
                DB::table('contacts')->where('company_id', $cid)->where('id', $s->contact_id)->update(['is_aktif' => (bool) $d['is_aktif']]);
            }

            $before = $s->only(self::EXT);
            $s->fill(array_intersect_key($d, array_flip(self::EXT)));
            if ($s->isDirty()) {
                $s->updated_by = $user->getKey();
                $s->save();
                $this->audit->log('supplier', $id, 'update', $before, $s->only(self::EXT));
            }

            return $this->present($s->refresh());
        });
    }

    public function find(int $id): array
    {
        return $this->present(Supplier::query()->find($id) ?? throw PurchasingException::notFound('Pemasok'));
    }

    public function list(?string $q, ?bool $aktif, int $limit = 50, ?int $afterId = null): array
    {
        $query = DB::table('suppliers as s')
            ->join('contacts as k', fn ($j) => $j->on('k.id', '=', 's.contact_id')->on('k.company_id', '=', 's.company_id'))
            ->where('s.company_id', $this->current->id())->orderBy('s.id')->select('s.id');

        if ($q !== null && $q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $query->where(fn ($w) => $w->where('k.kode', 'like', $like)->orWhere('k.nama', 'like', $like));
        }
        if ($aktif !== null) {
            $query->where('k.is_aktif', $aktif);
        }
        if ($afterId) {
            $query->where('s.id', '>', $afterId);
        }

        $ids = $query->limit($limit + 1)->pluck('id');
        $more = $ids->count() > $limit;
        $rows = Supplier::query()->whereIn('id', $ids->take($limit))->orderBy('id')->get()->map(fn ($s) => $this->present($s))->all();

        return ['data' => $rows, 'next_after_id' => $more ? (int) $ids->take($limit)->last() : null];
    }

    /** Utang berjalan = jumlah sisa tagihan faktur pembelian Posted pemasok itu. */
    public function outstanding(int $contactId): string
    {
        return Money::round((string) (DB::table('purchase_invoices')->where('company_id', $this->current->id())
            ->where('contact_id', $contactId)->where('status', 'posted')->sum('sisa_tagihan')));
    }

    private function present(Supplier $s): array
    {
        $k = DB::table('contacts')->where('company_id', $s->company_id)->where('id', $s->contact_id)->first();

        return [
            'id' => (int) $s->getKey(), 'contact_id' => (int) $s->contact_id,
            'kode' => $k->kode, 'nama' => $k->nama, 'alamat' => $k->alamat, 'kota' => $k->kota, 'telepon' => $k->telepon,
            'email' => $k->email, 'npwp' => $k->npwp, 'is_aktif' => (bool) $k->is_aktif,
            'supplier_category_id' => $s->supplier_category_id, 'payment_term_id' => $s->payment_term_id,
            'akun_utang_override_id' => $s->akun_utang_override_id, 'no_rekening' => $s->no_rekening,
            'utang_berjalan' => $this->outstanding((int) $s->contact_id),
        ];
    }

    private function assertExt(array $d): void
    {
        if (! empty($d['payment_term_id']) && ! PaymentTerm::query()->whereKey($d['payment_term_id'])->where('is_aktif', true)->exists()) {
            throw PurchasingException::notFound('Syarat pembayaran');
        }
        if (! empty($d['supplier_category_id']) && ! DB::table('supplier_categories')->where('company_id', $this->current->id())->where('id', $d['supplier_category_id'])->exists()) {
            throw PurchasingException::notFound('Kategori pemasok');
        }
        if (! empty($d['akun_utang_override_id'])) {
            $a = Account::query()->find($d['akun_utang_override_id']) ?? throw PurchasingException::notFound('Akun utang');
            if ($a->sub_tipe !== 'payable' || ! $a->isPostable()) {
                throw new PurchasingException("Akun {$a->kode} bukan akun utang yang bisa dipakai.", 'account_invalid');
            }
        }
    }
}
