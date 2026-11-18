<?php

namespace App\Modules\Sales\Customers;

use App\Models\User;
use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Accounting\PaymentTerms\PaymentTerm;
use App\Modules\Accounting\Support\Money;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Tenancy\CurrentCompany;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\SalesException;
use Illuminate\Support\Facades\DB;

/**
 * Master Pelanggan (UC-28). Pelanggan = ekstensi 1:1 dari contacts: satu pihak bisa jadi pelanggan sekaligus pemasok tanpa data ganda.
 * 'contact_id' yang sudah ada dapat dijadikan pelanggan; bila tidak, kontak baru dibuat (kode, nama wajib).
 */
class CustomerService
{
    private const CONTACT = ['kode', 'nama', 'alamat', 'kota', 'telepon', 'email', 'npwp'];
    private const EXT = ['customer_category_id', 'limit_kredit', 'payment_term_id', 'level_harga', 'salesperson_id', 'akun_piutang_override_id'];

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
                    ?? throw SalesException::notFound('Kontak');
                if (DB::table('customers')->where('company_id', $cid)->where('contact_id', $contact->id)->exists()) {
                    throw new SalesException('Kontak ini sudah menjadi pelanggan.', 'customer_exists');
                }
                DB::table('contacts')->where('id', $contact->id)->update(['is_pelanggan' => true, 'updated_by' => $user->getKey(), 'updated_at' => $now]);
                $contactId = (int) $contact->id;
            } else {
                foreach (['kode', 'nama'] as $f) {
                    if (empty(trim((string) ($d[$f] ?? '')))) {
                        throw new SalesException("Field {$f} wajib diisi.", 'required_field');
                    }
                }
                if (DB::table('contacts')->where('company_id', $cid)->where('kode', trim($d['kode']))->exists()) {
                    throw new SalesException("Kode kontak '{$d['kode']}' sudah dipakai.", 'duplicate_code');
                }
                $contactId = (int) DB::table('contacts')->insertGetId(array_intersect_key($d, array_flip(self::CONTACT)) + [
                    'company_id' => $cid, 'is_pelanggan' => true, 'is_aktif' => true,
                    'created_by' => $user->getKey(), 'created_at' => $now, 'updated_by' => $user->getKey(), 'updated_at' => $now,
                ]);
            }

            $c = Customer::create(array_intersect_key($d, array_flip(self::EXT)) + [
                'contact_id' => $contactId, 'limit_kredit' => $d['limit_kredit'] ?? '0', 'level_harga' => $d['level_harga'] ?? 1,
                'created_by' => $user->getKey(), 'updated_by' => $user->getKey(),
            ]);
            $this->audit->log('customer', (int) $c->getKey(), 'create', null, ['contact_id' => $contactId] + array_intersect_key($d, array_flip(self::EXT)));

            return $this->present($c);
        });
    }

    public function update(int $id, array $d, User $user): array
    {
        $this->assertExt($d);

        return DB::transaction(function () use ($id, $d, $user) {
            $c = Customer::query()->lockForUpdate()->find($id) ?? throw SalesException::notFound('Pelanggan');
            $cid = $this->current->id();

            $contact = array_intersect_key($d, array_flip(self::CONTACT));
            if (isset($contact['kode']) && DB::table('contacts')->where('company_id', $cid)->where('kode', $contact['kode'])->where('id', '!=', $c->contact_id)->exists()) {
                throw new SalesException("Kode kontak '{$contact['kode']}' sudah dipakai.", 'duplicate_code');
            }
            if ($contact) {
                DB::table('contacts')->where('company_id', $cid)->where('id', $c->contact_id)->update($contact + ['updated_by' => $user->getKey(), 'updated_at' => now()]);
            }
            if (array_key_exists('is_aktif', $d)) {
                DB::table('contacts')->where('company_id', $cid)->where('id', $c->contact_id)->update(['is_aktif' => (bool) $d['is_aktif']]);
            }

            $before = $c->only(self::EXT);
            $c->fill(array_intersect_key($d, array_flip(self::EXT)));
            if ($c->isDirty()) {
                $c->updated_by = $user->getKey();
                $c->save();
                $this->audit->log('customer', $id, 'update', $before, $c->only(self::EXT));
            }

            return $this->present($c->refresh());
        });
    }

    public function find(int $id): array
    {
        return $this->present(Customer::query()->find($id) ?? throw SalesException::notFound('Pelanggan'));
    }

    public function list(?string $q, ?bool $aktif, int $limit = 50, ?int $afterId = null): array
    {
        $cid = $this->current->id();
        $query = DB::table('customers as c')->join('contacts as k', fn ($j) => $j->on('k.id', '=', 'c.contact_id')->on('k.company_id', '=', 'c.company_id'))
            ->where('c.company_id', $cid)->orderBy('c.id')->select('c.id');
        if ($q !== null && $q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $query->where(fn ($w) => $w->where('k.kode', 'like', $like)->orWhere('k.nama', 'like', $like));
        }
        if ($aktif !== null) {
            $query->where('k.is_aktif', $aktif);
        }
        if ($afterId) {
            $query->where('c.id', '>', $afterId);
        }

        $ids = $query->limit($limit + 1)->pluck('id');
        $more = $ids->count() > $limit;
        $rows = Customer::query()->whereIn('id', $ids->take($limit))->orderBy('id')->get()->map(fn ($c) => $this->present($c))->all();

        return ['data' => $rows, 'next_after_id' => $more ? (int) $ids->take($limit)->last() : null];
    }

    /** Piutang berjalan = jumlah sisa tagihan faktur Posted pelanggan itu (untuk peringatan limit kredit). */
    public function outstanding(int $contactId): string
    {
        return Money::round((string) (DB::table('sales_invoices')->where('company_id', $this->current->id())
            ->where('contact_id', $contactId)->where('status', 'posted')->sum('sisa_tagihan')));
    }

    private function present(Customer $c): array
    {
        $k = DB::table('contacts')->where('company_id', $c->company_id)->where('id', $c->contact_id)->first();

        return [
            'id' => (int) $c->getKey(), 'contact_id' => (int) $c->contact_id,
            'kode' => $k->kode, 'nama' => $k->nama, 'alamat' => $k->alamat, 'kota' => $k->kota, 'telepon' => $k->telepon,
            'email' => $k->email, 'npwp' => $k->npwp, 'is_aktif' => (bool) $k->is_aktif,
            'customer_category_id' => $c->customer_category_id, 'limit_kredit' => (string) $c->limit_kredit,
            'payment_term_id' => $c->payment_term_id, 'level_harga' => (int) $c->level_harga,
            'salesperson_id' => $c->salesperson_id, 'akun_piutang_override_id' => $c->akun_piutang_override_id,
            'piutang_berjalan' => $this->outstanding((int) $c->contact_id),
        ];
    }

    private function assertExt(array $d): void
    {
        $cid = $this->current->id();
        if (isset($d['limit_kredit']) && (! is_numeric($d['limit_kredit']) || Money::cmp((string) $d['limit_kredit'], '0') < 0)) {
            throw new SalesException('Limit kredit harus angka tidak negatif.', 'credit_limit_invalid');
        }
        if (! empty($d['payment_term_id']) && ! PaymentTerm::query()->whereKey($d['payment_term_id'])->where('is_aktif', true)->exists()) {
            throw SalesException::notFound('Syarat pembayaran');
        }
        if (! empty($d['salesperson_id']) && ! DB::table('contacts')->where('company_id', $cid)->where('id', $d['salesperson_id'])->where('is_karyawan', true)->exists()) {
            throw new SalesException('Salesperson harus kontak bertanda karyawan.', 'salesperson_invalid');
        }
        if (! empty($d['akun_piutang_override_id'])) {
            $a = Account::query()->find($d['akun_piutang_override_id']) ?? throw SalesException::notFound('Akun piutang');
            if ($a->sub_tipe !== 'receivable' || ! $a->isPostable()) {
                throw new SalesException("Akun {$a->kode} bukan akun piutang yang bisa dipakai.", 'account_invalid');
            }
        }
    }
}
