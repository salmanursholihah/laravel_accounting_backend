<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Posting\PostingException;
use App\Modules\Accounting\Posting\PostingHandlerRegistry;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Platform\Models\ApprovalRule;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Kelola aturan approval (hak khusus ubah_preferensi: Owner dan Admin; dipasang di route).
 * Satu aturan per (doc_type, aksi) per perusahaan. Aturan tidak dihapus (dirujuk pengajuan lama): cukup dinonaktifkan.
 */
class ApprovalRuleController extends Controller
{
    private const KONDISI_KEYS = ['amount_gte', 'creator_role_not_in'];

    public function index(): JsonResponse
    {
        return response()->json(['data' => ApprovalRule::query()->orderBy('doc_type')->orderBy('aksi')->get()->map(fn ($r) => $this->present($r))->values()]);
    }

    public function store(Request $request, PostingHandlerRegistry $handlers, CurrentCompany $current, AuditLogger $audit): JsonResponse
    {
        $d = $this->validated($request, $handlers, $current, true);
        if (ApprovalRule::query()->where('doc_type', $d['doc_type'])->where('aksi', $d['aksi'])->exists()) {
            throw ValidationException::withMessages(['doc_type' => 'Aturan untuk dokumen dan aksi ini sudah ada; ubah aturan yang ada.']);
        }

        $rule = ApprovalRule::create($d + ['is_aktif' => $d['is_aktif'] ?? true]);
        $audit->log('approval_rule', (int) $rule->getKey(), 'create', null, $this->present($rule), null, $current->id(), (int) $request->user()->getKey());

        return response()->json(['data' => $this->present($rule)], 201);
    }

    public function update(Request $request, int $id, PostingHandlerRegistry $handlers, CurrentCompany $current, AuditLogger $audit): JsonResponse
    {
        $rule = ApprovalRule::query()->findOrFail($id);
        $before = $this->present($rule);
        $d = $this->validated($request, $handlers, $current, false);
        unset($d['doc_type'], $d['aksi']); // identitas aturan tidak diubah; buat aturan baru bila perlu

        $rule->fill($d)->save();
        $audit->log('approval_rule', (int) $rule->getKey(), 'update', $before, $this->present($rule), null, $current->id(), (int) $request->user()->getKey());

        return response()->json(['data' => $this->present($rule)]);
    }

    public function deactivate(Request $request, int $id, CurrentCompany $current, AuditLogger $audit): JsonResponse
    {
        $rule = ApprovalRule::query()->findOrFail($id);
        $before = $this->present($rule);
        $rule->forceFill(['is_aktif' => false])->save();
        $audit->log('approval_rule', (int) $rule->getKey(), 'deactivate', $before, $this->present($rule), null, $current->id(), (int) $request->user()->getKey());

        return response()->json(['data' => $this->present($rule)]);
    }

    private function validated(Request $request, PostingHandlerRegistry $handlers, CurrentCompany $current, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $d = $request->validate([
            'doc_type' => [$req, 'string', 'max:40'],
            'aksi' => [$req, 'in:post,void'],
            'kondisi_json' => ['sometimes', 'nullable', 'array'],
            'kondisi_json.amount_gte' => ['sometimes', 'numeric', 'min:0'],
            'kondisi_json.creator_role_not_in' => ['sometimes', 'array'],
            'kondisi_json.creator_role_not_in.*' => ['string', 'max:60'],
            'approver_role_id' => [$req, 'integer'],
            'is_aktif' => ['sometimes', 'boolean'],
        ]);

        if (isset($d['doc_type']) && $d['doc_type'] !== ApprovalRule::ANY_DOC) {
            try {
                $handlers->get($d['doc_type']);
            } catch (PostingException) {
                throw ValidationException::withMessages(['doc_type' => "Jenis dokumen '{$d['doc_type']}' tidak dikenal."]);
            }
        }
        // kunci kondisi tak dikenal ditolak di sini agar aturan tidak diam-diam berubah arti (evaluator fail-closed)
        // dibaca dari input mentah: validate() hanya mengembalikan kunci yang punya aturan, sehingga kunci asing akan lolos diam-diam
        $raw = $request->input('kondisi_json');
        $unknown = is_array($raw) ? array_diff(array_keys($raw), self::KONDISI_KEYS) : [];
        if ($unknown !== []) {
            throw ValidationException::withMessages(['kondisi_json' => 'Kunci kondisi tidak dikenal: ' . implode(', ', $unknown) . '.']);
        }
        if (isset($d['approver_role_id'])
            && ! Role::query()->whereKey($d['approver_role_id'])->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $current->id()))->exists()) {
            throw ValidationException::withMessages(['approver_role_id' => 'Role penyetuju tidak ditemukan.']);
        }

        return $d;
    }

    private function present(ApprovalRule $r): array
    {
        return [
            'id' => $r->id, 'doc_type' => $r->doc_type, 'aksi' => $r->aksi, 'kondisi_json' => $r->kondisi_json,
            'approver_role_id' => $r->approver_role_id, 'is_aktif' => $r->is_aktif,
        ];
    }
}
