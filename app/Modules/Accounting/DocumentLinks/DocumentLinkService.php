<?php

namespace App\Modules\Accounting\DocumentLinks;

use App\Modules\Platform\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * Rantai dokumen generik (tabel document_links): satu pola untuk semua alur (Tahap 5 bagian 14 no. 4).
 * Dipakai posting dokumen turunan untuk mencatat tautan, dan void (ALG-07) untuk mencari turunan aktif penghalang.
 * Hanya membaca kolom umum status/nomor tiap tabel dokumen, tanpa mengetahui modul pemiliknya.
 */
class DocumentLinkService
{
    /** Jenis dokumen (= kunci penomoran) => tabel header. */
    public const TABLES = [
        'sales_quotation' => 'sales_quotations', 'sales_order' => 'sales_orders', 'sales_delivery' => 'sales_deliveries',
        'sales_down_payment' => 'sales_down_payments', 'sales_invoice' => 'sales_invoices', 'sales_receipt' => 'sales_receipts',
        'sales_return' => 'sales_returns',
        'purchase_order' => 'purchase_orders', 'goods_receipt' => 'goods_receipts', 'purchase_down_payment' => 'purchase_down_payments',
        'purchase_invoice' => 'purchase_invoices', 'purchase_payment' => 'purchase_payments', 'purchase_return' => 'purchase_returns',
    ];

    public function __construct(private CurrentCompany $current)
    {
    }

    public function link(string $sourceType, int $sourceId, string $targetType, int $targetId, ?string $qty = null, ?string $nominal = null, ?int $sourceLineId = null, ?int $targetLineId = null): void
    {
        DB::table('document_links')->insert([
            'company_id' => $this->current->id(), 'source_type' => $sourceType, 'source_id' => $sourceId, 'source_line_id' => $sourceLineId,
            'target_type' => $targetType, 'target_id' => $targetId, 'target_line_id' => $targetLineId,
            'qty' => $qty, 'nominal' => $nominal, 'created_by' => auth()->id(), 'created_at' => now(), 'updated_by' => auth()->id(), 'updated_at' => now(),
        ]);
    }

    /**
     * Dokumen turunan (target) yang masih aktif, yaitu bukan Void. Daftar penghalang void (ALG-07).
     *
     * @return list<array{doc_type:string, doc_id:int, nomor:?string}>
     */
    public function activeDescendants(string $sourceType, int $sourceId): array
    {
        $cid = $this->current->id();
        $links = DB::table('document_links')->where('company_id', $cid)->where('source_type', $sourceType)->where('source_id', $sourceId)
            ->get(['target_type', 'target_id'])->unique(fn ($l) => $l->target_type . ':' . $l->target_id);

        $out = [];
        foreach ($links as $l) {
            $table = self::TABLES[$l->target_type] ?? null;
            if ($table === null) {
                continue;
            }
            $row = DB::table($table)->where('company_id', $cid)->where('id', $l->target_id)->first(['nomor', 'status']);
            if ($row && $row->status !== 'void') {
                $out[] = ['doc_type' => $l->target_type, 'doc_id' => (int) $l->target_id, 'nomor' => $row->nomor];
            }
        }

        return $out;
    }
}
