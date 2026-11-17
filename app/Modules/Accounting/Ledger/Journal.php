<?php

namespace App\Modules\Accounting\Ledger;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jurnal Posted. Tabel journals tidak punya kolom status dan kebal UPDATE/DELETE (trigger MySQL):
 * "void" berarti ada jurnal pembalik (reversed_of_id = id jurnal ini). Pakai scope withReversalInfo()
 * untuk mengetahui apakah sebuah jurnal sudah dibalik.
 */
class Journal extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $table = 'journals';

    protected $fillable = [
        'company_id', 'nomor', 'tanggal', 'period_id', 'tipe', 'source_type', 'source_id', 'reversed_of_id',
        'is_opening', 'is_closing', 'memo', 'total_debit', 'total_kredit', 'branch_id', 'created_by',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'is_opening' => 'boolean',
        'is_closing' => 'boolean',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }

    /** Menambahkan kolom dibalik_oleh_id / dibalik_oleh_nomor (null bila belum dibalik). */
    public function scopeWithReversalInfo($query)
    {
        return $query
            ->leftJoin('journals as rv', function ($j) {
                $j->on('rv.reversed_of_id', '=', 'journals.id')->on('rv.company_id', '=', 'journals.company_id');
            })
            ->select('journals.*', 'rv.id as dibalik_oleh_id', 'rv.nomor as dibalik_oleh_nomor');
    }

    /** Jurnal yang masih berlaku: bukan jurnal pembalik dan belum dibalik. */
    public function scopeActive($query)
    {
        return $query->where('journals.tipe', '!=', 'reversal')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('journals as rv')
                    ->whereColumn('rv.reversed_of_id', 'journals.id')
                    ->whereColumn('rv.company_id', 'journals.company_id');
            });
    }

    public function isReversed(): bool
    {
        return static::query()->where('reversed_of_id', $this->getKey())->exists();
    }
}
