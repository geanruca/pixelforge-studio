<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EstimateGenerationRequest extends FormRequest
{
    /**
     * The estimate depends on the prompt's length, not its text, so only the
     * character count travels: the request stays tiny while the user types.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'characters' => ['required', 'integer', 'min:0', 'max:'.StoreGenerationRequest::MAX_PROMPT_LENGTH],
            'size' => ['required', Rule::enum(GenerationSize::class)],
            'quality' => ['required', Rule::enum(GenerationQuality::class)],
            'reference_images' => ['required', 'integer', 'min:0', 'max:'.StoreGenerationRequest::MAX_REFERENCE_IMAGES],
        ];
    }
}
