<script setup lang="ts">
import { computed } from 'vue';
import { splitPrompt } from '@/lib/promptKeywords';

const props = defineProps<{ prompt: string }>();

const parts = computed(() => splitPrompt(props.prompt));
</script>

<template>
    <!-- No whitespace between the parts: what is copied must be the prompt exactly. -->
    <span
        ><template v-for="(part, index) in parts" :key="index"
            ><span
                v-if="part.keyword"
                class="rounded border px-1 py-px font-mono text-[0.8em] whitespace-nowrap"
                :class="part.keyword.classes"
                :title="part.keyword.hint"
                data-test="prompt-keyword"
                >{{ part.text }}</span
            ><template v-else>{{ part.text }}</template></template
        ></span
    >
</template>
