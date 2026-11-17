<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

use App\Contracts\ImageProvider;
use App\Exceptions\PixelforgeException;
use App\Models\Generation;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;

/**
 * The Pixelforge adapter of {@see ImageProvider}, and the only class that
 * talks HTTP to Pixelforge. It never retries on its own:
 * it reports what happened as a {@see PixelforgeException} and the caller (a
 * queued job) decides whether and when to try again, honouring `Retry-After`.
 */
class PixelforgeClient implements ImageProvider
{
    public const string NAME = 'pixelforge';

    public function __construct(private readonly Http $http) {}

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Submit a generation. The generation's own idempotency key is sent, so
     * repeating the call after a timeout returns the generation that already
     * exists instead of creating (and billing) a second one.
     *
     * @throws PixelforgeException
     */
    public function createGeneration(Generation $generation): ProviderGeneration
    {
        $payload = [
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'metadata' => [ProviderGeneration::LOCAL_ID_METADATA_KEY => (string) $generation->id],
        ];

        if ($generation->reference_images !== []) {
            $payload['reference_images'] = $generation->reference_images;
        }

        $response = $this->send(fn (PendingRequest $request): Response => $request
            ->withHeaders(['Idempotency-Key' => $generation->idempotency_key])
            ->post('generations', $payload));

        return $this->generation($response);
    }

    /**
     * @throws PixelforgeException
     */
    public function getGeneration(string $providerId): ProviderGeneration
    {
        return $this->generation($this->send(
            fn (PendingRequest $request): Response => $request->get('generations/'.rawurlencode($providerId)),
        ));
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws PixelforgeException
     */
    public function pricing(): array
    {
        return $this->json($this->send(fn (PendingRequest $request): Response => $request->get('pricing')));
    }

    /**
     * Billed usage for this API key per UTC day: the source of truth for what
     * the provider charged us.
     *
     * @return array<array-key, mixed>
     *
     * @throws PixelforgeException
     */
    public function usage(CarbonInterface $startDate, CarbonInterface $endDate): array
    {
        return $this->json($this->send(fn (PendingRequest $request): Response => $request->get('usage', [
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
        ])));
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws PixelforgeException
     */
    private function send(callable $call): Response
    {
        $apiKey = config('pixelforge.api_key');

        $request = $this->http
            ->baseUrl(config()->string('pixelforge.base_url'))
            ->withToken(is_string($apiKey) ? $apiKey : '')
            ->acceptJson()
            ->asJson()
            ->connectTimeout(config()->integer('pixelforge.connect_timeout'))
            ->timeout(config()->integer('pixelforge.timeout'));

        try {
            $response = $call($request);
        } catch (ConnectionException $exception) {
            throw PixelforgeException::connectionFailed($exception->getMessage());
        }

        if ($response->successful()) {
            return $response;
        }

        $error = $response->json('error');
        $error = is_array($error) ? $error : [];
        $retryAfter = $response->header('Retry-After');

        throw new PixelforgeException(
            errorCode: is_string($error['code'] ?? null) ? $error['code'] : PixelforgeException::UNEXPECTED_RESPONSE,
            message: is_string($error['message'] ?? null) ? $error['message'] : "Pixelforge answered HTTP {$response->status()}.",
            httpStatus: $response->status(),
            retryAfterSeconds: ctype_digit($retryAfter) ? (int) $retryAfter : null,
        );
    }

    /**
     * @throws PixelforgeException
     */
    private function generation(Response $response): ProviderGeneration
    {
        try {
            return ProviderGeneration::fromApi($this->json($response));
        } catch (InvalidArgumentException $exception) {
            throw PixelforgeException::unexpected($exception->getMessage(), $response->status());
        }
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws PixelforgeException
     */
    private function json(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            throw PixelforgeException::unexpected('Pixelforge returned a body that is not JSON.', $response->status());
        }

        return $body;
    }
}
