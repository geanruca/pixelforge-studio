<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, Pencil } from '@lucide/vue';
import { nextTick, ref, watch } from 'vue';
import { update } from '@/routes/generations';

/**
 * The name of an image, edited in place. Saving is optimistic: the new name
 * shows at once and the request happens behind it; if the server refuses, the
 * previous name comes back with the reason.
 */
const props = defineProps<{
    generationId: number;
    title: string | null;
    /** Ask for the name outright (used while the image is still generating). */
    prompting?: boolean;
}>();

const MAX_LENGTH = 80;

const value = ref(props.title ?? '');
const saved = ref(props.title ?? '');
const editing = ref(false);
const justSaved = ref(false);
const error = ref<string | null>(null);
const input = ref<HTMLInputElement | null>(null);

// Follow the server while idle, never while the user is typing.
watch(
    () => props.title,
    (title) => {
        if (!editing.value) {
            value.value = title ?? '';
            saved.value = title ?? '';
        }
    },
);

const startEditing = async (): Promise<void> => {
    editing.value = true;
    await nextTick();
    input.value?.focus();
};

const commit = (): void => {
    editing.value = false;
    const next = value.value.trim();
    value.value = next;

    if (next === saved.value) {
        return;
    }

    const previous = saved.value;
    saved.value = next;
    error.value = null;

    router.patch(
        update.url(props.generationId),
        { title: next },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['generations'],
            onSuccess: () => {
                justSaved.value = true;
                window.setTimeout(() => (justSaved.value = false), 1800);
            },
            onError: (errors) => {
                saved.value = previous;
                value.value = previous;
                error.value = errors.title ?? 'The name could not be saved.';
            },
        },
    );
};

const cancel = (): void => {
    value.value = saved.value;
    editing.value = false;
};
</script>

<template>
    <div class="min-w-0">
        <form
            v-if="prompting || editing"
            class="flex items-center gap-2"
            @submit.prevent="commit"
        >
            <label :for="`title-${generationId}`" class="sr-only">
                Name this image
            </label>
            <input
                :id="`title-${generationId}`"
                ref="input"
                v-model="value"
                type="text"
                :maxlength="MAX_LENGTH"
                autocomplete="off"
                placeholder="Give it a name (optional)"
                class="h-8 w-full min-w-0 rounded-md border border-input bg-background px-2 text-sm outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                :data-test="`title-input-${generationId}`"
                @focus="editing = true"
                @blur="commit"
                @keydown.esc.prevent="cancel"
            />
            <span
                v-if="justSaved"
                class="inline-flex shrink-0 items-center gap-1 text-xs text-emerald-700 dark:text-emerald-400"
                role="status"
            >
                <Check class="size-3.5" aria-hidden="true" />
                Saved
            </span>
        </form>

        <div v-else class="group flex items-center gap-1.5">
            <p
                class="truncate text-sm font-medium"
                :class="{ 'text-muted-foreground italic': saved === '' }"
            >
                {{ saved === '' ? 'Untitled' : saved }}
            </p>
            <button
                type="button"
                class="shrink-0 rounded p-1 text-muted-foreground opacity-60 transition-opacity group-hover:opacity-100 hover:text-foreground focus-visible:opacity-100 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                :aria-label="
                    saved === '' ? 'Name this image' : `Rename ${saved}`
                "
                @click="startEditing"
            >
                <Pencil class="size-3.5" aria-hidden="true" />
            </button>
            <span
                v-if="justSaved"
                class="inline-flex items-center gap-1 text-xs text-emerald-700 dark:text-emerald-400"
                role="status"
            >
                <Check class="size-3.5" aria-hidden="true" />
                Saved
            </span>
        </div>

        <p v-if="error" class="mt-1 text-xs text-red-600 dark:text-red-500">
            {{ error }}
        </p>
    </div>
</template>
