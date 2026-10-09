<?php

namespace Modules\Arca\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePointOfSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'number' => [
                'required',
                'integer',
                'min:1',
                Rule::unique((string) config('arca.table_prefix', 'arca_').'points_of_sale', 'number')
                    ->where('cuit', config('arca.cuit'))
                    ->where('environment', (bool) config('arca.production', false) ? 'production' : 'testing'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
