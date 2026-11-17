<?php

namespace App\Modules\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // setiap user terautentikasi boleh membuat perusahaan (menjadi Owner-nya)
    }

    public function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:190'],
            'npwp' => ['nullable', 'string', 'max:25'],
            'is_pkp' => ['sometimes', 'boolean'],
            'alamat' => ['nullable', 'string'],
            'bulan_mulai_tahun_buku' => ['sometimes', 'integer', 'between:1,12'],
            'tanggal_mulai_pembukuan' => ['required', 'date'],
            'template' => ['sometimes', 'in:dagang,jasa,manufaktur'],
        ];
    }
}
