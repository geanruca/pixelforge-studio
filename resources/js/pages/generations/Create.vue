<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { CircleAlert, Sparkles } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import ReferenceImageFields from '@/components/ReferenceImageFields.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { exactUsd, formatUsd } from '@/lib/money';
import { dashboard } from '@/routes';
import { index as credits } from '@/routes/credits';
import { estimate as estimateRoute, store } from '@/routes/generations';
import type { Option, PriceEstimate } from '@/types';

const props = defineProps<{
    sizes: Option[];
    qualities: Option[];
    limits: { prompt: number; reference_images: number };
    prefill: {
        prompt: string;
        size: string;
        quality: string;
        reference_images: string[];
    } | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generate', href: dashboard() }],
    },
});

const page = usePage();
const balance = computed(() => page.props.auth.user.balance_nanos);

const form = useForm({
    prompt: props.prefill?.prompt ?? '',
    size: props.prefill?.size ?? '1024x1024',
    quality: props.prefill?.quality ?? 'medium',
    reference_images: [...(props.prefill?.reference_images ?? [])],
});

// Counted the way the server and Pixelforge count: Unicode characters, not UTF-16 units.
const promptCharacters = computed(() => [...form.prompt.trim()].length);
const promptTooLong = computed(
    () => promptCharacters.value > props.limits.prompt,
);
const filledReferences = computed(() =>
    form.reference_images.filter((url) => url.trim() !== ''),
);

const price = ref<PriceEstimate | null>(null);
const estimating = ref(false);
const estimateError = ref<string | null>(null);
let latestRequest = 0;

const fetchEstimate = async (): Promise<void> => {
    if (promptTooLong.value) {
        price.value = null;
        estimating.value = false;

        return;
    }

    const request = ++latestRequest;

    try {
        const response = await fetch(
            estimateRoute.url({
                query: {
                    characters: promptCharacters.value,
                    size: form.size,
                    quality: form.quality,
                    reference_images: filledReferences.value.length,
                },
            }),
            { headers: { Accept: 'application/json' } },
        );

        // A slower, older request must not overwrite a newer estimate.
        if (request !== latestRequest) {
            return;
        }

        if (!response.ok) {
            throw new Error(`Estimate failed with ${response.status}`);
        }

        price.value = (
            (await response.json()) as { price: PriceEstimate }
        ).price;
        estimateError.value = null;
    } catch {
        if (request === latestRequest) {
            price.value = null;
            estimateError.value =
                'The price could not be estimated right now. Please try again in a moment.';
        }
    } finally {
        if (request === latestRequest) {
            estimating.value = false;
        }
    }
};

const refreshEstimate = useDebounceFn(fetchEstimate, 250);

watch(
    () => [
        promptCharacters.value,
        form.size,
        form.quality,
        filledReferences.value.length,
    ],
    () => {
        estimating.value = true;
        void refreshEstimate();
    },
);

onMounted(() => {
    estimating.value = true;
    void fetchEstimate();
});

const cannotAfford = computed(
    () => price.value !== null && price.value.maximum > balance.value,
);

const canSubmit = computed(
    () =>
        promptCharacters.value > 0 &&
        !promptTooLong.value &&
        price.value !== null &&
        !cannotAfford.value &&
        !form.processing,
);

const submit = (): void => {
    form.transform((data) => ({
        ...data,
        reference_images: data.reference_images
            .map((url) => url.trim())
            .filter((url) => url !== ''),
    })).post(store.url(), { preserveScroll: true });
};

// Errors the server keys by names that are not form fields.
const serverErrors = computed(
    () => form.errors as Record<string, string | undefined>,
);
const creditsError = computed(() => serverErrors.value.credits);
</script>

<template>
    <Head title="Generate" />

    <div class="mx-auto w-full max-w-5xl p-4 md:p-6">
        <Heading
            title="Generate an image"
            description="Describe what you want. You see the price before you submit and you are only charged when the image is delivered."
        />

        <form
            class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]"
            @submit.prevent="submit"
        >
            <div class="space-y-6">
                <div class="grid gap-2">
                    <div class="flex items-baseline justify-between">
                        <Label for="prompt">Prompt</Label>
                        <span
                            class="text-xs tabular-nums"
                            :class="
                                promptTooLong
                                    ? 'font-medium text-red-600 dark:text-red-500'
                                    : 'text-muted-foreground'
                            "
                            data-test="prompt-counter"
                        >
                            {{ promptCharacters.toLocaleString('en-US') }} /
                            {{ limits.prompt.toLocaleString('en-US') }}
                        </span>
                    </div>
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        rows="6"
                        required
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        placeholder="A lighthouse on a cliff at dawn, soft watercolor…"
                        :aria-invalid="
                            Boolean(form.errors.prompt) || promptTooLong
                        "
                    />
                    <InputError
                        :message="
                            form.errors.prompt ??
                            (promptTooLong
                                ? `The prompt can be at most ${limits.prompt.toLocaleString('en-US')} characters.`
                                : undefined)
                        "
                    />
                </div>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Size</legend>
                    <div class="grid gap-2 sm:grid-cols-3">
                        <label
                            v-for="size in sizes"
                            :key="size.value"
                            class="flex cursor-pointer items-center justify-center rounded-md border px-3 py-2 text-center text-sm transition-colors has-checked:border-primary has-checked:bg-primary has-checked:text-primary-foreground has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50"
                        >
                            <input
                                v-model="form.size"
                                type="radio"
                                name="size"
                                :value="size.value"
                                class="sr-only"
                            />
                            {{ size.label }}
                        </label>
                    </div>
                    <InputError :message="form.errors.size" />
                </fieldset>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Quality</legend>
                    <div class="grid grid-cols-3 gap-2">
                        <label
                            v-for="quality in qualities"
                            :key="quality.value"
                            class="flex cursor-pointer items-center justify-center rounded-md border px-3 py-2 text-sm transition-colors has-checked:border-primary has-checked:bg-primary has-checked:text-primary-foreground has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50"
                        >
                            <input
                                v-model="form.quality"
                                type="radio"
                                name="quality"
                                :value="quality.value"
                                class="sr-only"
                            />
                            {{ quality.label }}
                        </label>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Higher quality uses more tokens, so it costs more and
                        its final price varies more.
                    </p>
                    <InputError :message="form.errors.quality" />
                </fieldset>

                <div class="grid gap-2">
                    <Label>
                        Reference images
                        <span class="font-normal text-muted-foreground">
                            (optional, up to {{ limits.reference_images }})
                        </span>
                    </Label>
                    <ReferenceImageFields
                        v-model="form.reference_images"
                        :max="limits.reference_images"
                        :errors="serverErrors"
                    />
                    <p class="text-xs text-muted-foreground">
                        Each reference image adds a fixed amount to the price.
                    </p>
                </div>
            </div>

            <aside
                class="h-fit space-y-4 rounded-xl border p-4 lg:sticky lg:top-4"
            >
                <div aria-live="polite" data-test="estimate">
                    <p class="text-sm text-muted-foreground">Estimated price</p>
                    <p
                        v-if="price"
                        class="flex items-center gap-2 text-3xl font-semibold tabular-nums"
                        :title="exactUsd(price.expected)"
                    >
                        ≈ {{ formatUsd(price.expected) }}
                        <Spinner v-if="estimating" class="size-4" />
                    </p>
                    <p v-else-if="estimating" class="py-1.5">
                        <Spinner class="size-5" />
                        <span class="sr-only">Calculating price…</span>
                    </p>
                    <p v-else class="text-3xl font-semibold">—</p>

                    <p v-if="price" class="mt-1 text-sm text-muted-foreground">
                        Between
                        <span class="tabular-nums">{{
                            formatUsd(price.minimum, 'down')
                        }}</span>
                        and
                        <span
                            class="font-medium text-foreground tabular-nums"
                            >{{ formatUsd(price.maximum, 'up') }}</span
                        >
                    </p>
                    <p
                        v-if="estimateError"
                        class="mt-1 text-sm text-red-600 dark:text-red-500"
                    >
                        {{ estimateError }}
                    </p>
                </div>

                <ul class="space-y-1.5 text-xs text-muted-foreground">
                    <li>
                        The exact price is only known once the image is done, so
                        this is an estimate.
                    </li>
                    <li>
                        We reserve the maximum now and charge the actual price
                        on delivery. The difference returns to your balance.
                    </li>
                    <li>Failed generations cost nothing.</li>
                </ul>

                <div
                    class="flex items-center justify-between border-t pt-3 text-sm"
                >
                    <span class="text-muted-foreground">Available credits</span>
                    <span
                        class="font-medium tabular-nums"
                        :title="exactUsd(balance)"
                    >
                        {{ formatUsd(balance, 'down') }}
                    </span>
                </div>

                <Alert
                    v-if="cannotAfford || creditsError"
                    variant="destructive"
                >
                    <CircleAlert aria-hidden="true" />
                    <AlertTitle>
                        {{
                            cannotAfford
                                ? 'Not enough credits'
                                : 'Not submitted'
                        }}
                    </AlertTitle>
                    <AlertDescription>
                        <p v-if="creditsError">{{ creditsError }}</p>
                        <p v-else-if="price">
                            This could cost up to
                            {{ formatUsd(price.maximum, 'up') }} and you have
                            {{ formatUsd(balance, 'down') }} available.
                        </p>
                        <Link
                            :href="credits()"
                            class="font-medium underline underline-offset-4"
                        >
                            Buy credits
                        </Link>
                    </AlertDescription>
                </Alert>

                <Button
                    type="submit"
                    class="w-full"
                    :disabled="!canSubmit"
                    data-test="generate-button"
                >
                    <Spinner v-if="form.processing" />
                    <Sparkles v-else class="size-4" aria-hidden="true" />
                    Generate
                </Button>
            </aside>
        </form>
    </div>
</template>
