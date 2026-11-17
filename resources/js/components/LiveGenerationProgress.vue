<script setup lang="ts">
import { useNow } from '@vueuse/core';
import { Check, CircleDashed, LoaderCircle } from '@lucide/vue';
import { computed } from 'vue';
import { formatDuration, secondsBetween } from '@/lib/duration';
import { formatUsd } from '@/lib/money';
import type { Generation } from '@/types';

/**
 * What the user watches instead of a spinner: every step of a generation,
 * told as it happens, with what it means for their credits.
 */
const props = defineProps<{
    generation: Generation;
    /** Place in the line for a provider slot, when still waiting for one. */
    queuePosition: number | null;
}>();

// Pixelforge's own numbers: 5–30 s of processing, 60–90 s for slow ones.
const USUAL_PROCESSING_SECONDS = 30;

const now = useNow({ interval: 1000 });

type Step = {
    key: string;
    state: 'done' | 'current' | 'upcoming';
    title: string;
    detail: string;
    meta: string | null;
};

const elapsed = computed(() =>
    secondsBetween(props.generation.created_at, now.value),
);

const steps = computed<Step[]>(() => {
    const { generation } = props;
    const { accepted_at, started_at, retry_attempt, max_attempts } =
        generation.progress;
    const accepted = accepted_at !== null;
    const processing = generation.status === 'processing';
    const ahead = (props.queuePosition ?? 1) - 1;

    const waiting: Step = accepted
        ? {
              key: 'accepted',
              state: 'done',
              title: 'Accepted by the image service',
              detail: 'Your request has a slot.',
              meta: `after ${formatDuration(secondsBetween(generation.created_at, accepted_at))}`,
          }
        : retry_attempt > 0
          ? {
                key: 'accepted',
                state: 'current',
                title: 'The image service is busy — retrying',
                detail: `Trying again automatically (attempt ${retry_attempt + 1} of ${max_attempts}). If it never gets through, your reservation is returned in full.`,
                meta: null,
            }
          : {
                key: 'accepted',
                state: 'current',
                title: 'Waiting for a free slot',
                detail:
                    ahead > 0
                        ? `${ahead} ${ahead === 1 ? 'request is' : 'requests are'} ahead of yours. Only three images are generated at a time.`
                        : 'You are next. Only three images are generated at a time.',
                meta: null,
            };

    const runningFor =
        started_at === null ? 0 : secondsBetween(started_at, now.value);

    const generating: Step = processing
        ? {
              key: 'generating',
              state: 'current',
              title: 'Generating your image',
              detail:
                  runningFor > USUAL_PROCESSING_SECONDS
                      ? 'Taking longer than usual, and still working. Some images need up to a minute and a half.'
                      : 'This usually takes 5 to 30 seconds.',
              meta: `running for ${formatDuration(runningFor)}`,
          }
        : {
              key: 'generating',
              state: accepted ? 'current' : 'upcoming',
              title: accepted ? 'Starting' : 'Generate the image',
              detail: accepted
                  ? 'In the image service queue; it starts in a few seconds.'
                  : 'Starts as soon as a slot is free.',
              meta: null,
          };

    return [
        {
            key: 'reserved',
            state: 'done',
            title: 'Credits reserved',
            detail: `Up to ${formatUsd(generation.reserved_nanos, 'up')} is set aside. You will only pay the final price.`,
            meta: null,
        },
        waiting,
        generating,
        {
            key: 'delivered',
            state: 'upcoming',
            title: 'Deliver and charge',
            detail: `Expected around ${formatUsd(generation.estimated_price_nanos)}. Whatever is left of the reservation returns to your balance; if it fails, all of it does.`,
            meta: null,
        },
    ];
});

const headline = computed(
    () => steps.value.find((step) => step.state === 'current')?.title ?? '',
);
</script>

<template>
    <div :data-test="`live-${generation.id}`">
        <div
            class="live-canvas relative flex w-full flex-col items-center justify-center gap-1 overflow-hidden bg-muted px-4 text-center"
            :class="
                generation.size === '1536x1024'
                    ? 'aspect-[3/2]'
                    : generation.size === '1024x1536'
                      ? 'aspect-[2/3]'
                      : 'aspect-square'
            "
        >
            <p
                class="font-mono text-3xl font-semibold tabular-nums"
                aria-hidden="true"
            >
                {{ formatDuration(elapsed) }}
            </p>
            <p class="text-sm text-muted-foreground" aria-live="polite">
                {{ headline }}
            </p>
        </div>

        <ol class="space-y-3 p-4" aria-label="Progress of this generation">
            <li
                v-for="step in steps"
                :key="step.key"
                class="flex gap-3"
                :class="{ 'opacity-55': step.state === 'upcoming' }"
                :data-state="step.state"
                :aria-current="step.state === 'current' ? 'step' : undefined"
            >
                <span
                    class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full"
                    :class="
                        step.state === 'done'
                            ? 'bg-emerald-600 text-white'
                            : step.state === 'current'
                              ? 'bg-sky-600 text-white'
                              : 'border text-muted-foreground'
                    "
                >
                    <Check
                        v-if="step.state === 'done'"
                        class="size-3"
                        aria-hidden="true"
                    />
                    <LoaderCircle
                        v-else-if="step.state === 'current'"
                        class="size-3 animate-spin motion-reduce:animate-none"
                        aria-hidden="true"
                    />
                    <CircleDashed v-else class="size-3" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-sm font-medium">
                        {{ step.title }}
                        <span
                            v-if="step.meta"
                            class="font-normal text-muted-foreground tabular-nums"
                        >
                            · {{ step.meta }}
                        </span>
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ step.detail }}
                    </p>
                </div>
            </li>
        </ol>
    </div>
</template>

<style scoped>
/* A slow sweep across the canvas: something is happening, without a spinner. */
.live-canvas::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(
        100deg,
        transparent 30%,
        color-mix(in oklab, var(--color-foreground) 7%, transparent) 50%,
        transparent 70%
    );
    background-size: 200% 100%;
    animation: live-sweep 2.4s linear infinite;
}

.live-canvas > * {
    position: relative;
}

@keyframes live-sweep {
    from {
        background-position: 200% 0;
    }

    to {
        background-position: -200% 0;
    }
}

@media (prefers-reduced-motion: reduce) {
    .live-canvas::before {
        animation: none;
    }
}
</style>
