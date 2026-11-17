<?php

namespace App\Modules\Accounting\Periods;

use App\Models\User;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Tahun buku dan periode akuntansi (Tahap 4 bagian 7). Selalu bekerja pada perusahaan aktif.
 * Dipakai orkestrator posting (ALG-02) lewat assertPostable().
 */
class PeriodService
{
    public function __construct(
        private CurrentCompany $current,
        private AuditLogger $audit,
    ) {
    }

    /**
     * Membuat tahun buku beserta 12 periode. `tahun` = tahun kalender dari tanggal mulai tahun buku
     * (mis. tahun buku Juli 2026 - Juni 2027 bertahun 2026).
     */
    public function createFiscalYear(int $tahun): FiscalYear
    {
        $company = $this->current->company();
        $start = CarbonImmutable::create($tahun, (int) $company->bulan_mulai_tahun_buku, 1)->startOfDay();
        $end = $start->addYear()->subDay();

        return DB::transaction(function () use ($tahun, $start, $end) {
            if (FiscalYear::query()->where('tahun', $tahun)->exists()) {
                throw new DomainException("Tahun buku {$tahun} sudah ada.");
            }
            if (FiscalYear::query()
                ->where('tanggal_mulai', '<=', $end->toDateString())
                ->where('tanggal_selesai', '>=', $start->toDateString())
                ->exists()) {
                throw new DomainException('Rentang tahun buku bertumpuk dengan tahun buku yang sudah ada.');
            }

            $fy = FiscalYear::create([
                'tahun' => $tahun,
                'tanggal_mulai' => $start->toDateString(),
                'tanggal_selesai' => $end->toDateString(),
                'status' => 'open',
            ]);

            for ($i = 0; $i < 12; $i++) {
                $ps = $start->addMonths($i);
                AccountingPeriod::create([
                    'fiscal_year_id' => $fy->getKey(),
                    'bulan' => $i + 1,
                    'tanggal_mulai' => $ps->toDateString(),
                    'tanggal_selesai' => $ps->endOfMonth()->toDateString(),
                    'status' => 'open',
                ]);
            }

            $this->audit->log('fiscal_year', (int) $fy->getKey(), 'create', null, [
                'tahun' => $tahun, 'mulai' => $start->toDateString(), 'selesai' => $end->toDateString(),
            ]);

            return $fy;
        });
    }

    public function periodFor(CarbonImmutable|string $date): ?AccountingPeriod
    {
        $d = CarbonImmutable::parse($date)->toDateString();

        return AccountingPeriod::query()
            ->where('tanggal_mulai', '<=', $d)
            ->where('tanggal_selesai', '>=', $d)
            ->first();
    }

    /**
     * Validasi 4 dan 5 aturan jurnal: tanggal tidak lebih awal dari tanggal mulai pembukuan
     * dan jatuh di periode Open. Mengembalikan periodenya (untuk journals.period_id).
     */
    public function assertPostable(CarbonImmutable|string $date): AccountingPeriod
    {
        $d = CarbonImmutable::parse($date)->toDateString();
        $mulai = $this->current->company()->tanggal_mulai_pembukuan->toDateString();

        if ($d < $mulai) {
            throw PeriodException::beforeBookStart($d, $mulai);
        }

        $period = $this->periodFor($d) ?? throw PeriodException::notFound($d);

        if (! $period->isOpen()) {
            throw PeriodException::closed($period);
        }

        return $period;
    }

    public function isPostable(CarbonImmutable|string $date): bool
    {
        try {
            $this->assertPostable($date);

            return true;
        } catch (PeriodException) {
            return false;
        }
    }

    /**
     * Primitif penguncian. Dipanggil oleh wizard Proses Akhir Bulan (ALG-11) setelah checklist lulus;
     * sengaja belum diekspos lewat endpoint karena checklist-nya dibangun di step berikutnya.
     */
    public function close(AccountingPeriod $period, User $user): void
    {
        if (! $period->isOpen()) {
            throw new DomainException('Periode sudah dikunci.');
        }

        DB::transaction(function () use ($period, $user) {
            $period->update(['status' => 'closed', 'ditutup_oleh' => $user->getKey(), 'ditutup_pada' => now()]);
            $this->audit->log('accounting_period', (int) $period->getKey(), 'close', ['status' => 'open'], ['status' => 'closed'], null, null, (int) $user->getKey());
        });
    }

    /** UC-14: hanya hak khusus buka_kunci_periode (Owner). Alasan wajib. */
    public function reopen(AccountingPeriod $period, User $user, string $alasan): void
    {
        if ($period->isOpen()) {
            throw new DomainException('Periode sudah terbuka.');
        }
        if (trim($alasan) === '') {
            throw new DomainException('Alasan wajib diisi.');
        }
        if ($period->fiscalYear->status === 'closed') {
            throw new DomainException('Tahun buku sudah ditutup. Buka kembali lewat proses tutup tahun.');
        }

        DB::transaction(function () use ($period, $user, $alasan) {
            $period->update(['status' => 'open', 'ditutup_oleh' => null, 'ditutup_pada' => null]);
            $this->audit->log('accounting_period', (int) $period->getKey(), 'reopen', ['status' => 'closed'], ['status' => 'open'], $alasan, null, (int) $user->getKey());
        });
    }
}
