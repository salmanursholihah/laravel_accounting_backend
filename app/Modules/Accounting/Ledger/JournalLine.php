<?php

namespace App\Modules\Accounting\Ledger;

use App\Modules\Accounting\ChartOfAccounts\Account;
use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'journal_lines';

    protected $fillable = [
        'company_id', 'journal_id', 'line_no', 'account_id', 'debit', 'kredit', 'memo', 'contact_id', 'tanggal',
    ];

    protected $casts = ['tanggal' => 'date'];

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
