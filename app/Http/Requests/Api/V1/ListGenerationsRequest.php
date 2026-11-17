<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\GenerationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListGenerationsRequest extends FormRequest
{
    public const int DEFAULT_LIMIT = 20;

    public const int MAX_LIMIT = 100;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'status' => ['sometimes', Rule::enum(GenerationStatus::class)],
            'cursor' => ['sometimes', 'string', 'max:500'],
        ];
    }
}
