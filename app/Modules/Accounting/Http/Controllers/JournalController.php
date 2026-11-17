<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Ledger\GeneralJournalService;
use App\Modules\Accounting\Ledger\Journal;
use App\Modules\Accounting\Ledger\JournalLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** UC-17 Jurnal Umum: daftar, detail, posting, jurnal koreksi, void. */
class JournalController extends Controller
{
    private const AMOUNT_RULE = ['nullable', 'regex:/^\d+(\.\d{1,4})?$/'];

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'dari' => ['sometimes', 'date'], 'sampai' => ['sometimes', 'date', 'after_or_equal:dari'],
            'tipe' => ['sometimes', 'in:general,auto,correction,opening,closing,reversal'],
            'source_type' => ['sometimes', 'string', 'max:60'], 'source_id' => ['sometimes', 'integer'],
            'q' => ['sometimes', 'string', 'max:50'],
            'before_id' => ['sometimes', 'integer', 'min:1'], 'limit' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $limit = (int) $request->input('limit', 50);
        $q = Journal::query()->withReversalInfo()->orderByDesc('journals.id');

        if ($request->filled('dari')) {
            $q->where('journals.tanggal', '>=', $request->date('dari')->toDateString());
        }
        if ($request->filled('sampai')) {
            $q->where('journals.tanggal', '<=', $request->date('sampai')->toDateString());
        }
        foreach (['tipe', 'source_type', 'source_id'] as $f) {
            if ($request->filled($f)) {
                $q->where("journals.{$f}", $request->input($f));
            }
        }
        if ($request->filled('q')) {
            $q->where('journals.nomor', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $request->input('q')) . '%');
        }
        if ($request->filled('before_id')) {
            $q->where('journals.id', '<', (int) $request->input('before_id'));
        }

        $rows = $q->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return response()->json([
            'data' => $rows->map(fn ($j) => $this->present($j))->values(),
            'next_before_id' => $more ? $rows->last()->id : null,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $j = Journal::query()->withReversalInfo()->where('journals.id', $id)->firstOrFail();

        $lines = JournalLine::query()
            ->join('accounts as a', function ($q) {
                $q->on('a.id', '=', 'journal_lines.account_id')->on('a.company_id', '=', 'journal_lines.company_id');
            })
            ->where('journal_lines.journal_id', $j->id)
            ->orderBy('journal_lines.line_no')
            ->get(['journal_lines.line_no', 'journal_lines.account_id', 'a.kode', 'a.nama', 'journal_lines.debit', 'journal_lines.kredit', 'journal_lines.memo', 'journal_lines.contact_id']);

        $asal = $j->reversed_of_id ? Journal::query()->whereKey($j->reversed_of_id)->value('nomor') : null;

        return response()->json(['data' => $this->present($j) + ['dibalik_dari_nomor' => $asal, 'lines' => $lines]]);
    }

    public function store(Request $request, GeneralJournalService $svc): JsonResponse
    {
        $data = $request->validate([
            'tanggal' => ['required', 'date'],
            'memo' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:2', 'max:500'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.debit' => self::AMOUNT_RULE,
            'lines.*.kredit' => self::AMOUNT_RULE,
            'lines.*.memo' => ['nullable', 'string', 'max:500'],
            'lines.*.contact_id' => ['nullable', 'integer'],
        ]);
        $data['tanggal'] = $request->date('tanggal')->toDateString();

        $j = $svc->createGeneral($data, $request->user());

        return response()->json(['data' => ['id' => $j->id, 'nomor' => $j->nomor, 'tanggal' => $j->tanggal->toDateString()]], 201);
    }

    /** Jurnal koreksi manual (hak khusus jurnal_koreksi di route). */
    public function correct(Request $request, GeneralJournalService $svc): JsonResponse
    {
        $data = $request->validate([
            'journal_asal_id' => ['required', 'integer'],
            'tanggal' => ['required', 'date'],
            'alasan' => ['required', 'string', 'max:400'],
            'lines' => ['required', 'array', 'min:2', 'max:500'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.debit' => self::AMOUNT_RULE,
            'lines.*.kredit' => self::AMOUNT_RULE,
            'lines.*.memo' => ['nullable', 'string', 'max:500'],
            'lines.*.contact_id' => ['nullable', 'integer'],
        ]);
        $data['tanggal'] = $request->date('tanggal')->toDateString();

        $j = $svc->createCorrection($data, $request->user());

        return response()->json(['data' => ['id' => $j->id, 'nomor' => $j->nomor, 'tanggal' => $j->tanggal->toDateString()]], 201);
    }

    public function void(Request $request, int $id, GeneralJournalService $svc): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:400']]);

        $rev = $svc->void($id, $data['alasan'], $request->user());

        return response()->json(['data' => ['id' => $rev->id, 'nomor' => $rev->nomor, 'tanggal' => $rev->tanggal->toDateString()]]);
    }

    private function present(Journal $j): array
    {
        return [
            'id' => $j->id, 'nomor' => $j->nomor, 'tanggal' => $j->tanggal->toDateString(), 'tipe' => $j->tipe,
            'source_type' => $j->source_type, 'source_id' => $j->source_id, 'reversed_of_id' => $j->reversed_of_id,
            'memo' => $j->memo, 'total_debit' => $j->total_debit, 'total_kredit' => $j->total_kredit,
            'status' => $j->tipe === 'reversal' ? 'pembalik' : ($j->dibalik_oleh_id ? 'void' : 'posted'),
            'dibalik_oleh_id' => $j->dibalik_oleh_id, 'dibalik_oleh_nomor' => $j->dibalik_oleh_nomor,
            'created_by' => $j->created_by,
        ];
    }
}
