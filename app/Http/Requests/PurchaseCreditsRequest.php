<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseCreditsRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'pack' => ['required', 'integer', Rule::in(config()->array('pixelforge.credit_packs_usd'))],
            // Generated once per click by the browser: a double submit or a
            // retried request carries the same reference and is credited once.
            'reference' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pack.in' => 'Choose one of the available credit packs.',
        ];
    }
}
