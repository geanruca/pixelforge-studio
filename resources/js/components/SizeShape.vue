<script setup lang="ts">
import { computed } from 'vue';

/**
 * A small outlined rectangle with the proportions of an image size, so a size
 * can be recognised at a glance instead of read.
 */
const props = withDefaults(
    defineProps<{
        /** Width × height in pixels, e.g. "1536x1024". */
        size: string;
        /** Length in pixels of the longer side of the drawn shape. */
        scale?: number;
    }>(),
    { scale: 22 },
);

const style = computed(() => {
    const [width, height] = props.size.split('x').map(Number);
    const longest = Math.max(width, height) || 1;

    return {
        width: `${Math.round((width / longest) * props.scale)}px`,
        height: `${Math.round((height / longest) * props.scale)}px`,
    };
});
</script>

<template>
    <span
        class="inline-block shrink-0 rounded-[3px] border-[1.5px] border-current"
        :style="style"
        aria-hidden="true"
    />
</template>
