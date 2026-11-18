<?php

namespace App\Modules\Accounting\Posting;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Kontrak yang diimplementasikan tiap modul bisnis untuk dokumen akuntansinya (Faktur Penjualan, Pembayaran, dst.).
 * Handler TIDAK menulis jurnal atau stok sendiri: ia hanya menyusun masukan dan memperbarui dokumennya sendiri.
 * Orkestrator (ALG-02) yang memegang urutan, transaksi, dan penguncian.
 */
interface PostingHandler
{
    /** Kunci jenis dokumen = jenis penomoran = source_type jurnal (mis. 'sales_invoice'). */
    public function docType(): string;

    /** Fitur RBAC dokumen (mis. 'faktur_penjualan'), untuk aksi post dan void. */
    public function feature(): string;

    /** Muat dokumen perusahaan aktif; $lock = SELECT ... FOR UPDATE. ModelNotFoundException bila tidak ada. */
    public function find(int $id, bool $lock = false): Model;

    public function tanggal(Model $doc): string;

    /** draft | pending_approval | posted | void */
    public function status(Model $doc): string;

    /** Validasi spesifik dokumen sebelum transaksi (limit kredit, stok, dst.). Lempar PostingException/DomainException. */
    public function validate(Model $doc, User $user): void;

    /** Masukan TaxCalculator::calculate() atau null bila dokumen tanpa pajak. */
    public function taxInput(Model $doc): ?array;

    /** Gerakan stok dokumen (lihat StockMovementApplier::apply). */
    public function stockMovements(Model $doc): array;

    /** Susun konteks ALG-04 (values, flags, lists, account_overrides, contact_id) dari pajak dan hasil stok. */
    public function ruleContext(Model $doc, ?array $taxes, StockMovementResult $stock): array;

    /** Perbarui dokumen sendiri: nomor, status posted, journal_id, qty terpenuhi, sisa tagihan, rincian pajak. */
    public function markPosted(Model $doc, PostingResult $result, User $user): void;

    /** Dokumen turunan aktif yang menghalangi void: daftar ['doc_type' => ..., 'nomor' => ...]. */
    public function blockers(Model $doc): array;

    public function isReconciled(Model $doc): bool;

    /** Pulihkan dokumen sumber (qty terpenuhi dikurangi, status Open/Partial) dan saldo terbuka (sisa tagihan, uang muka). */
    public function restoreSources(Model $doc): void;

    public function markVoid(Model $doc, string $reason, User $user): void;

    /** Isi tambahan payload outbox (mis. contact_id, total) untuk event DocumentPosted/DocumentVoided. */
    public function eventPayload(Model $doc): array;

    /** Memo jurnal otomatis. */
    public function journalMemo(Model $doc): ?string;
}
