<?php

namespace App\Modules\Accounting\Ledger;

use DomainException;
use Illuminate\Http\JsonResponse;

/** Pelanggaran aturan jurnal (8 validasi Tahap 4 bagian 2 dan aturan void ALG-07). */
class JournalException extends DomainException
{
    public function __construct(string $message, public readonly string $errorCode = 'journal_error', public readonly array $context = [])
    {
        parent::__construct($message);
    }

    public static function tooFewLines(): self
    {
        return new self('Jurnal minimal memiliki 2 baris.', 'too_few_lines');
    }

    public static function invalidAmount(int $line, string $field): self
    {
        return new self("Baris {$line}: nominal {$field} harus angka tidak negatif dengan paling banyak 4 desimal.", 'invalid_amount', ['baris' => $line]);
    }

    public static function lineBothSides(int $line): self
    {
        return new self("Baris {$line}: satu baris hanya boleh berisi debit atau kredit, tidak keduanya.", 'line_both_sides', ['baris' => $line]);
    }

    public static function lineEmpty(int $line): self
    {
        return new self("Baris {$line}: debit atau kredit wajib diisi lebih dari nol.", 'line_empty', ['baris' => $line]);
    }

    public static function unbalanced(string $debit, string $kredit): self
    {
        return new self(
            "Jurnal tidak seimbang: total debit {$debit}, total kredit {$kredit}.",
            'unbalanced',
            ['total_debit' => $debit, 'total_kredit' => $kredit],
        );
    }

    public static function accountNotFound(int $accountId): self
    {
        return new self("Akun #{$accountId} tidak ditemukan di perusahaan ini.", 'account_not_found', ['account_id' => $accountId]);
    }

    public static function accountHeader(string $kode): self
    {
        return new self("Akun {$kode} adalah akun induk (header) dan tidak bisa dipakai transaksi.", 'account_header', ['kode' => $kode]);
    }

    public static function accountInactive(string $kode): self
    {
        return new self("Akun {$kode} nonaktif dan tidak bisa dipilih.", 'account_inactive', ['kode' => $kode]);
    }

    public static function contactNotFound(int $contactId): self
    {
        return new self("Kontak #{$contactId} tidak ditemukan di perusahaan ini.", 'contact_not_found', ['contact_id' => $contactId]);
    }

    public static function sourceRequired(): self
    {
        return new self('Setiap jurnal wajib menyimpan source_type dan source_id.', 'source_required');
    }

    public static function unknownType(string $tipe): self
    {
        return new self("Tipe jurnal '{$tipe}' tidak dikenal.", 'unknown_type');
    }

    public static function noUser(): self
    {
        return new self('Pengguna pembuat jurnal tidak diketahui.', 'no_user');
    }

    public static function reasonRequired(): self
    {
        return new self('Alasan wajib diisi.', 'reason_required');
    }

    public static function alreadyReversed(string $nomor): self
    {
        return new self("Jurnal {$nomor} sudah dibalik.", 'already_reversed', ['nomor' => $nomor]);
    }

    public static function notReversible(string $message): self
    {
        return new self($message, 'not_reversible');
    }

    public static function openingAlreadyPosted(): self
    {
        return new self('Saldo awal sudah diposting. Void jurnal saldo awal terlebih dahulu bila perlu mengubahnya.', 'opening_already_posted');
    }

    public static function openingEmpty(): self
    {
        return new self('Belum ada baris saldo awal untuk diposting.', 'opening_empty');
    }

    public static function contactRequired(string $kode): self
    {
        return new self("Akun {$kode} adalah akun piutang/utang: saldo awalnya wajib diisi per kontak (pelanggan/pemasok).", 'contact_required', ['kode' => $kode]);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode] + ($this->context ? ['context' => $this->context] : []), 422);
    }
}
