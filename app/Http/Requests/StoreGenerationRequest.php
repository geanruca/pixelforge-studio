<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\GenerationQuality;
use App\Enums\GenerationSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    public const int MAX_PROMPT_LENGTH = 4000;

    public const int MAX_REFERENCE_IMAGES = 4;

    /**
     * Mirrors Pixelforge's own limits, so a request we accept (and reserve
     * credits for) is not rejected by the provider a moment later.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:'.self::MAX_PROMPT_LENGTH],
            'size' => ['required', Rule::enum(GenerationSize::class)],
            'quality' => ['required', Rule::enum(GenerationQuality::class)],
            'reference_images' => ['array', 'max:'.self::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'prompt.required' => 'Describe the image you want to generate.',
            'prompt.max' => 'The prompt can be at most :max characters.',
            'reference_images.max' => 'You can attach at most :max reference images.',
            'reference_images.*.url' => 'Each reference image must be a valid http(s) URL.',
            'reference_images.*.required' => 'Remove empty reference image fields or fill them in.',
        ];
    }

    /**
     * @return list<string>
     */
    public function referenceImages(): array
    {
        return array_values(array_filter($this->array('reference_images'), is_string(...)));
    }
}
