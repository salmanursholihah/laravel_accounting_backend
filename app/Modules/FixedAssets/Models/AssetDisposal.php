<?php

namespace App\Modules\FixedAssets\Models;

use App\Modules\Platform\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Disposisi (penjualan/penghapusan) aset tetap. Draft -> Posted -> Void; satu disposisi aktif per aset. */
class AssetDisposal extends Model
{
    use BelongsToCompany;

    protected $table = 'asset_disposals';

    protected $guarded = [];

    protected $casts = ['tanggal' => 'date', 'version' => 'integer', 'diposting_pada' => 'datetime', 'void_pada' => 'datetime'];
}
