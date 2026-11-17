<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\WebhookOutcome;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\Generations\GenerationSynchronizer;
use App\Services\Generations\SyncOutcome;
use App\Services\Pixelforge\ProviderGeneration;
use App\Services\Pixelforge\WebhookSignature;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Receives Pixelforge events. Delivery is at-least-once and unordered, so:
 *
 *  - the event id is claimed in a unique column, in the SAME transaction as
 *    the event's effect: a duplicate can never apply twice, and an effect that
 *    fails rolls its claim back so Pixelforge's retry gets a clean attempt;
 *  - what is applied is the generation snapshot inside the event, through
 *    {@see GenerationSynchronizer}, which only ever moves a generation
 *    forward — the order events arrive in is irrelevant.
 *
 * The work is a few indexed queries, so it runs inline and answers well
 * inside Pixelforge's 10 second limit.
 */
class PixelforgeWebhookController extends Controller
{
    private const string GENERATION_EVENT_PREFIX = 'generation.';

    public function __invoke(Request $request, WebhookSignature $signature, GenerationSynchronizer $synchronizer): JsonResponse
    {
        $payload = $request->getContent();

        if (! $signature->isValid($payload, $request->header('Pixelforge-Signature'))) {
            return response()->json(['error' => 'Invalid signature.'], 401);
        }

        $event = json_decode($payload, true);
        $eventId = is_array($event) ? ($event['id'] ?? null) : null;
        $type = is_array($event) ? ($event['type'] ?? null) : null;

        if (! is_array($event) || ! is_string($eventId) || $eventId === '' || ! is_string($type)) {
            return response()->json(['error' => 'Malformed event.'], 400);
        }

        try {
            $outcome = DB::transaction(function () use ($request, $event, $eventId, $type, $synchronizer): WebhookOutcome {
                // Claim first: a concurrent duplicate blocks on the unique
                // index here and fails once this transaction commits.
                $record = WebhookEvent::query()->create([
                    'event_id' => $eventId,
                    'type' => $type,
                    'outcome' => WebhookOutcome::Ignored,
                    'delivery_attempt' => is_numeric($request->header('Pixelforge-Delivery-Attempt'))
                        ? (int) $request->header('Pixelforge-Delivery-Attempt')
                        : null,
                    'payload' => $event,
                    'created_at' => now(),
                ]);

                if (! str_starts_with($type, self::GENERATION_EVENT_PREFIX)) {
                    return WebhookOutcome::Ignored;
                }

                $object = data_get($event, 'data.object');
                $snapshot = ProviderGeneration::fromApi(is_array($object) ? $object : []);
                $generation = $this->findGeneration($snapshot);

                $outcome = match (true) {
                    $generation === null => WebhookOutcome::Ignored,
                    $synchronizer->apply($generation, $snapshot) === SyncOutcome::Applied => WebhookOutcome::Applied,
                    default => WebhookOutcome::Stale,
                };

                $record->update(['provider_generation_id' => $snapshot->id, 'outcome' => $outcome]);

                return $outcome;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Only a second delivery of an event we already hold is a
            // duplicate. Any other unique violation is a real failure and
            // must reach Pixelforge as one, so that it retries.
            if (! WebhookEvent::query()->where('event_id', $eventId)->exists()) {
                throw $exception;
            }

            return response()->json(['received' => true, 'duplicate' => true]);
        } catch (InvalidArgumentException $exception) {
            Log::error('Pixelforge webhook carried an unusable generation', [
                'event_id' => $eventId,
                'type' => $type,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['error' => 'Unusable generation object.'], 422);
        }

        if ($outcome === WebhookOutcome::Ignored && str_starts_with($type, self::GENERATION_EVENT_PREFIX)) {
            Log::warning('Pixelforge webhook for a generation we do not know', ['event_id' => $eventId, 'type' => $type]);
        }

        return response()->json(['received' => true, 'outcome' => $outcome->value]);
    }

    /**
     * A webhook can arrive before the create request has returned, when we do
     * not know the provider id yet. Our own id travels in the generation's
     * metadata for exactly that case.
     */
    private function findGeneration(ProviderGeneration $snapshot): ?Generation
    {
        $generation = Generation::query()->where('provider_id', $snapshot->id)->first();

        if ($generation !== null || $snapshot->localId === null) {
            return $generation;
        }

        return Generation::query()->whereKey($snapshot->localId)->whereNull('provider_id')->first();
    }
}
