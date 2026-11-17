<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { ImageOff, RotateCcw, Sparkles } from '@lucide/vue';
import { reactive, watch } from 'vue';
import GenerationStatusBadge from '@/components/GenerationStatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { exactUsd, formatUsd } from '@/lib/money';
import { dashboard } from '@/routes';
import { index as history } from '@/routes/generations';
import type { Generation, Paginated } from '@/types';

const props = defineProps<{
    generations: Paginated<Generation>;
    hasActive: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'History', href: history() }],
    },
});

// Poll only while something is in flight; nothing changes once all are final.
const { start, stop } = usePoll(
    2500,
    { only: ['generations', 'hasActive', 'auth'] },
    { autoStart: false },
);

watch(
    () => props.hasActive,
    (active) => (active ? start() : stop()),
    { immediate: true },
);

// Images whose URL stopped loading (e.g. an expired provider link).
const brokenImages = reactive(new Set<number>());

const aspectClass = (size: string): string => {
    switch (size) {
        case '1536x1024':
            return 'aspect-[3/2]';
        case '1024x1536':
            return 'aspect-[2/3]';
        default:
            return 'aspect-square';
    }
};

const formatDate = (iso: string): string =>
    new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(iso));
</script>

<template>
    <Head title="History" />

    <div class="mx-auto w-full max-w-6xl p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="History"
                description="Every generation with its live status, what we estimated and what you were actually charged."
            />
            <Button as-child>
                <Link :href="dashboard()">
                    <Sparkles class="size-4" aria-hidden="true" />
                    New generation
                </Link>
            </Button>
        </div>

        <div
            v-if="generations.data.length === 0"
            class="rounded-xl border border-dashed p-10 text-center"
        >
            <p class="font-medium">No generations yet</p>
            <p class="mt-1 text-sm text-muted-foreground">
                Your images will show up here as soon as you generate one.
            </p>
        </div>

        <ul v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <li
                v-for="generation in generations.data"
                :key="generation.id"
                class="flex flex-col overflow-hidden rounded-xl border"
                :data-test="`generation-${generation.id}`"
            >
                <div
                    class="relative flex w-full items-center justify-center bg-muted"
                    :class="aspectClass(generation.size)"
                >
                    <a
                        v-if="
                            generation.image_url &&
                            !brokenImages.has(generation.id)
                        "
                        :href="generation.image_url"
                        target="_blank"
                        rel="noopener"
                        class="size-full"
                    >
                        <img
                            :src="generation.image_url"
                            :alt="generation.prompt"
                            loading="lazy"
                            class="size-full object-cover"
                            @error="brokenImages.add(generation.id)"
                        />
                    </a>
                    <div
                        v-else-if="
                            generation.status === 'queued' ||
                            generation.status === 'processing'
                        "
                        class="flex flex-col items-center gap-2 text-sm text-muted-foreground"
                    >
                        <Spinner class="size-6" />
                        {{
                            generation.status === 'processing'
                                ? 'Generating your image…'
                                : generation.awaiting_slot
                                  ? 'Waiting to start…'
                                  : 'In the queue…'
                        }}
                    </div>
                    <div
                        v-else
                        class="flex flex-col items-center gap-2 px-4 text-center text-sm text-muted-foreground"
                    >
                        <ImageOff class="size-6" aria-hidden="true" />
                        {{
                            generation.status === 'failed'
                                ? 'No image was produced'
                                : 'This image is no longer available'
                        }}
                    </div>
                </div>

                <div class="flex flex-1 flex-col gap-3 p-4">
                    <div class="flex items-center justify-between gap-2">
                        <GenerationStatusBadge
                            :status="generation.status"
                            :awaiting-slot="generation.awaiting_slot"
                        />
                        <time
                            class="text-xs text-muted-foreground"
                            :datetime="generation.created_at"
                        >
                            {{ formatDate(generation.created_at) }}
                        </time>
                    </div>

                    <p class="line-clamp-3 text-sm" :title="generation.prompt">
                        {{ generation.prompt }}
                    </p>

                    <p class="text-xs text-muted-foreground">
                        {{ generation.size.replace('x', ' × ') }} ·
                        {{ generation.quality }} quality
                        <template v-if="generation.reference_images.length">
                            ·
                            {{ generation.reference_images.length }} reference{{
                                generation.reference_images.length === 1
                                    ? ''
                                    : 's'
                            }}
                        </template>
                    </p>

                    <p
                        v-if="generation.error"
                        class="rounded-md bg-red-50 p-2 text-sm text-red-800 dark:bg-red-950 dark:text-red-200"
                        role="status"
                    >
                        {{ generation.error }}
                    </p>

                    <dl
                        class="mt-auto grid grid-cols-2 gap-2 border-t pt-3 text-sm"
                    >
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Estimated
                            </dt>
                            <dd
                                class="tabular-nums"
                                :title="
                                    exactUsd(generation.estimated_price_nanos)
                                "
                            >
                                ≈
                                {{
                                    formatUsd(generation.estimated_price_nanos)
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{
                                    generation.status === 'succeeded'
                                        ? 'Charged'
                                        : generation.status === 'failed'
                                          ? 'Charged'
                                          : 'Reserved'
                                }}
                            </dt>
                            <dd
                                v-if="
                                    generation.status === 'succeeded' &&
                                    generation.price_nanos !== null
                                "
                                class="font-medium tabular-nums"
                                :title="exactUsd(generation.price_nanos)"
                            >
                                {{ formatUsd(generation.price_nanos) }}
                            </dd>
                            <dd
                                v-else-if="generation.status === 'failed'"
                                class="font-medium"
                            >
                                $0 — not charged
                            </dd>
                            <dd
                                v-else
                                class="text-muted-foreground tabular-nums"
                                :title="`Up to ${exactUsd(generation.reserved_nanos)} is reserved until this finishes`"
                            >
                                up to
                                {{ formatUsd(generation.reserved_nanos, 'up') }}
                            </dd>
                        </div>
                    </dl>

                    <Button
                        v-if="generation.status === 'failed'"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <Link
                            :href="
                                dashboard({ query: { from: generation.id } })
                            "
                        >
                            <RotateCcw class="size-4" aria-hidden="true" />
                            Try again
                        </Link>
                    </Button>
                </div>
            </li>
        </ul>

        <nav
            v-if="generations.meta.last_page > 1"
            class="mt-6 flex items-center justify-between text-sm"
            aria-label="Pagination"
        >
            <Button
                variant="outline"
                size="sm"
                :disabled="!generations.links.prev"
                as-child
            >
                <Link
                    v-if="generations.links.prev"
                    :href="generations.links.prev"
                    >Newer</Link
                >
                <span v-else>Newer</span>
            </Button>
            <span class="text-muted-foreground">
                Page {{ generations.meta.current_page }} of
                {{ generations.meta.last_page }}
            </span>
            <Button
                variant="outline"
                size="sm"
                :disabled="!generations.links.next"
                as-child
            >
                <Link
                    v-if="generations.links.next"
                    :href="generations.links.next"
                    >Older</Link
                >
                <span v-else>Older</span>
            </Button>
        </nav>
    </div>
</template>
