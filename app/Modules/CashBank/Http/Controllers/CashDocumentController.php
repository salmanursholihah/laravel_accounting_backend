<?php

namespace App\Modules\CashBank\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CashBank\Documents\BankTransferService;
use App\Modules\CashBank\Documents\CashPaymentService;
use App\Modules\CashBank\Documents\CashReceiptService;
use App\Modules\CashBank\Documents\ExpenseService;
use App\Modules\CashBank\Documents\LineDocumentService;
use App\Modules\CashBank\Models\BankTransfer;
use App\Modules\CashBank\Models\CashPayment;
use App\Modules\CashBank\Models\CashReceipt;
use App\Modules\CashBank\Models\Expense;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD Draft untuk Penerimaan, Pembayaran, Pencatatan Beban, dan Transfer Bank. Jenis dokumen datang dari
 * default route ('doc'). Posting dan Void memakai endpoint generik /documents/{docType}/{id}/post|void.
 */
class CashDocumentController extends Controller
{
    private const DOCS = [
        'cash_receipt' => [CashReceiptService::class, CashReceipt::class],
        'cash_payment' => [CashPaymentService::class, CashPayment::class],
        'expense' => [ExpenseService::class, Expense::class],
        'bank_transfer' => [BankTransferService::class, BankTransfer::class],
    ];

    public function index(Request $request, string $doc): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'in:draft,pending_approval,posted,void'],
            'dari' => ['sometimes', 'date'], 'sampai' => ['sometimes', 'date', 'after_or_equal:dari'],
            'q' => ['sometimes', 'string', 'max:50'],
            'contact_id' => ['sometimes', 'integer'],
            'before_id' => ['sometimes', 'integer', 'min:1'], 'limit' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $limit = (int) $request->input('limit', 50);
        $q = (self::DOCS[$doc][1])::query()->orderByDesc('id');
        if ($doc !== 'bank_transfer') {
            $q->withCount('lines');
        }

        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }
        if ($request->filled('dari')) {
            $q->where('tanggal', '>=', $request->date('dari')->toDateString());
        }
        if ($request->filled('sampai')) {
            $q->where('tanggal', '<=', $request->date('sampai')->toDateString());
        }
        if ($request->filled('contact_id') && $doc !== 'bank_transfer') {
            $q->where('contact_id', $request->integer('contact_id'));
        }
        if ($request->filled('q')) {
            $q->where('nomor', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $request->input('q')) . '%');
        }
        if ($request->filled('before_id')) {
            $q->where('id', '<', $request->integer('before_id'));
        }

        $rows = $q->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return response()->json([
            'data' => $rows->map(fn (Model $d) => $this->present($doc, $d, false))->values(),
            'next_before_id' => $more ? $rows->last()->id : null,
        ]);
    }

    public function show(string $doc, int $id): JsonResponse
    {
        return response()->json(['data' => $this->present($doc, $this->service($doc)->find($id), true)]);
    }

    public function store(Request $request, string $doc): JsonResponse
    {
        $service = $this->service($doc);
        $data = $request->validate($service->rules());

        return response()->json(['data' => $this->present($doc, $service->create($data, $request->user()), true)], 201);
    }

    public function update(Request $request, string $doc, int $id): JsonResponse
    {
        $service = $this->service($doc);
        $data = $request->validate($service->rules());

        return response()->json(['data' => $this->present($doc, $service->update($id, $data, $request->user()), true)]);
    }

    public function destroy(Request $request, string $doc, int $id): JsonResponse
    {
        $this->service($doc)->delete($id, $request->user());

        return response()->json(null, 204);
    }

    private function service(string $doc): LineDocumentService|BankTransferService
    {
        abort_unless(isset(self::DOCS[$doc]), 404);

        return app(self::DOCS[$doc][0]);
    }

    private function present(string $doc, Model $d, bool $withLines): array
    {
        $base = [
            'id' => (int) $d->id, 'public_id' => $d->public_id, 'nomor' => $d->nomor,
            'tanggal' => $d->tanggal->toDateString(), 'status' => $d->status, 'memo' => $d->memo,
            'journal_id' => $d->journal_id, 'version' => (int) $d->version,
            'diposting_pada' => $d->diposting_pada?->toIso8601String(),
            'void_pada' => $d->void_pada?->toIso8601String(), 'alasan_void' => $d->alasan_void,
        ];

        if ($doc === 'bank_transfer') {
            return $base + [
                'rekening_asal_id' => (int) $d->rekening_asal_id, 'rekening_tujuan_id' => (int) $d->rekening_tujuan_id,
                'nominal' => (string) $d->nominal, 'biaya_admin' => (string) $d->biaya_admin,
            ];
        }

        $bankColumn = ['cash_receipt' => 'rekening_tujuan_id', 'cash_payment' => 'rekening_sumber_id', 'expense' => 'rekening_pembayaran_id'][$doc];
        $out = $base + [
            'contact_id' => $d->contact_id, 'rekening_id' => $d->{$bankColumn}, 'total' => (string) $d->total,
        ];
        if ($doc !== 'cash_receipt') {
            $out += ['dapat_dikreditkan' => (bool) $d->dapat_dikreditkan, 'no_faktur_pajak' => $d->no_faktur_pajak];
        }
        if ($doc === 'expense') {
            $out += ['jenis_pembayaran' => $d->jenis_pembayaran];
        }
        if (! $withLines) {
            return $out + ['jumlah_baris' => (int) ($d->lines_count ?? 0)];
        }

        return $out + ['lines' => $d->lines->map(fn ($l) => [
            'line_no' => (int) $l->line_no, 'account_id' => (int) $l->account_id, 'deskripsi' => $l->deskripsi,
            'nominal' => (string) $l->nominal, 'tax_code_id' => $l->tax_code_id,
        ])->values()];
    }
}
