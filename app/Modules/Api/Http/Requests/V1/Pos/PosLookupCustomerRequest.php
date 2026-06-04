<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Requests\V1\Pos;

use Illuminate\Foundation\Http\FormRequest;

class PosLookupCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Body POST — QR URL log-larında görünməsin. Format həm rotating
        // (`qr1.qr_xxx.ttt.hhh`) həm də static (`qr_xxx`) ola bilər; uzunluq cap-i 192
        // hər iki halı tutur, lakin açıq-aydın korlanmış payload-u erkən kəsir.
        return [
            'qr' => ['required', 'string', 'min:3', 'max:192'],
        ];
    }
}
