<?php

namespace App\Modules\Stock;

use DomainException;
use Illuminate\Http\JsonResponse;

/** Galat domain modul stok. render() mengembalikan JSON seperti exception domain modul lain. */
class StockException extends DomainException
{
    public function __construct(string $message, public readonly string $errorCode = 'stock_error', public readonly array $detail = [], public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }

    public static function insufficient(string $kodeBarang, string $gudang, string $tersedia, string $diminta): self
    {
        return new self("Stok {$kodeBarang} di gudang {$gudang} tidak cukup: tersedia {$tersedia}, diminta {$diminta}.", 'insufficient_stock', ['barang' => $kodeBarang, 'gudang' => $gudang, 'tersedia' => $tersedia, 'diminta' => $diminta]);
    }

    public static function notStockItem(string $kode): self
    {
        return new self("Barang {$kode} bertipe jasa atau tidak ditemukan; hanya barang yang punya stok.", 'not_stock_item', ['barang' => $kode]);
    }

    public static function inactive(string $apa, string $kode): self
    {
        return new self("{$apa} {$kode} nonaktif.", 'inactive', ['kode' => $kode]);
    }

    public static function priceRequired(string $kode): self
    {
        return new self("Harga masuk wajib diisi untuk {$kode}: belum ada HPP rata-rata.", 'price_required', ['barang' => $kode]);
    }

    public static function closedRange(string $dari, string $sampai): self
    {
        return new self("Transaksi mundur menyentuh periode terkunci ({$dari} s.d. {$sampai}); gunakan tanggal di periode terbuka atau buka kunci periode.", 'backdated_closed_period', ['dari' => $dari, 'sampai' => $sampai]);
    }

    public static function conflict(): self
    {
        return new self('Dokumen sudah diubah pengguna lain. Muat ulang lalu ulangi.', 'conflict', [], 409);
    }

    public static function invalid(string $message, string $code = 'invalid'): self
    {
        return new self($message, $code);
    }

    public static function inUse(string $apa): self
    {
        return new self("{$apa} sudah dipakai dan tidak bisa dihapus. Nonaktifkan saja.", 'in_use');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode] + ($this->detail ? ['detail' => $this->detail] : []), $this->httpStatus);
    }
}
