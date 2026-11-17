<?php

namespace App\Modules\Accounting\Ledger;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * UC-17 Jurnal Umum dan jurnal koreksi (Tahap 4 bagian 8).
 * Catatan: tabel journals hanya menyimpan jurnal Posted, jadi Jurnal Umum langsung diposting.
 * Draf jurnal umum dan approval untuk non-Akuntan menyusul bersama modul approval.
 */
class GeneralJournalService
{
    public function __construct(private JournalService $journals)
    {
    }

    /** @param array{tanggal:string, memo?:?string, lines:array, branch_id?:?int} $data */
    public function createGeneral(array $data, User $user): Journal
    {
        return $this->journals->post(new JournalDraft(
            tanggal: $data['tanggal'],
            tipe: 'general',
            lines: $data['lines'],
            memo: $data['memo'] ?? null,
            sourceType: 'manual',
            branchId: $data['branch_id'] ?? null,
            userId: (int) $user->getKey(),
        ));
    }

    /**
     * Jurnal koreksi manual dengan referensi ke jurnal asal; jurnal asal tidak diubah.
     * Hak khusus jurnal_koreksi dicek di route. Alasan koreksi wajib (masuk memo).
     *
     * @param array{journal_asal_id:int, tanggal:string, alasan:string, lines:array} $data
     */
    public function createCorrection(array $data, User $user): Journal
    {
        $alasan = trim((string) ($data['alasan'] ?? ''));
        if ($alasan === '') {
            throw JournalException::reasonRequired();
        }

        return DB::transaction(function () use ($data, $user, $alasan) {
            $asal = Journal::query()->findOrFail((int) $data['journal_asal_id']); // terfilter perusahaan aktif
            if ($asal->tipe === 'reversal') {
                throw JournalException::notReversible('Koreksi tidak bisa merujuk jurnal pembalik. Rujuk jurnal aslinya.');
            }

            return $this->journals->post(new JournalDraft(
                tanggal: $data['tanggal'],
                tipe: 'correction',
                lines: $data['lines'],
                memo: mb_substr("Koreksi {$asal->nomor}: {$alasan}", 0, 500),
                sourceType: 'journal',
                sourceId: (int) $asal->getKey(),
                userId: (int) $user->getKey(),
            ));
        });
    }

    /**
     * Void jurnal umum atau jurnal koreksi. Jurnal otomatis dokumen TIDAK boleh dibalik langsung:
     * ia dibatalkan lewat Void dokumen sumbernya agar stok dan status dokumen ikut dipulihkan (ALG-07).
     */
    public function void(int $journalId, string $reason, User $user): Journal
    {
        $j = Journal::query()->findOrFail($journalId);

        if (! in_array($j->tipe, ['general', 'correction'], true)) {
            throw JournalException::notReversible(
                $j->tipe === 'auto'
                    ? 'Jurnal ini dibuat otomatis oleh dokumen. Void dokumen sumbernya, bukan jurnalnya.'
                    : 'Jurnal ini tidak bisa di-void dari layar Jurnal Umum.'
            );
        }

        return $this->journals->reverse($j, $reason, (int) $user->getKey());
    }
}
