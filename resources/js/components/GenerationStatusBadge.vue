<script setup lang="ts">
import { CircleCheck, CircleX, Clock, LoaderCircle } from '@lucide/vue';
import { computed } from 'vue';
import type { GenerationStatus } from '@/types';

const props = defineProps<{
    status: GenerationStatus;
    awaitingSlot?: boolean;
}>();

const presentation = computed(() => {
    switch (props.status) {
        case 'queued':
            return {
                label: props.awaitingSlot
                    ? 'Waiting to start'
                    : 'Queued',
                icon: Clock,
                classes:
                    'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200',
                spin: false,
            };
        case 'processing':
            return {
                label: 'Processing',
                icon: LoaderCircle,
                classes:
                    'border-sky-300 bg-sky-50 text-sky-800 dark:border-sky-800 dark:bg-sky-950 dark:text-sky-200',
                spin: true,
            };
        case 'succeeded':
            return {
                label: 'Succeeded',
                icon: CircleCheck,
                classes:
                    'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
                spin: false,
            };
        default:
            return {
                label: 'Failed',
                icon: CircleX,
                classes:
                    'border-red-300 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200',
                spin: false,
            };
    }
});
</script>

<template>
    <span
        class="inline-flex w-fit items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium whitespace-nowrap"
        :class="presentation.classes"
        :data-status="status"
    >
        <component
            :is="presentation.icon"
            class="size-3"
            :class="{ 'animate-spin': presentation.spin }"
            aria-hidden="true"
        />
        {{ presentation.label }}
    </span>
</template>
