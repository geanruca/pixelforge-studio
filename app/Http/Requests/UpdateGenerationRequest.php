<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGenerationRequest extends FormRequest
{
    public const int MAX_TITLE_LENGTH = 80;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['present', 'nullable', 'string', 'max:'.self::MAX_TITLE_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.max' => 'A name can be at most :max characters.',
        ];
    }
}
