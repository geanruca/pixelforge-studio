<script setup lang="ts">
import { computed } from 'vue';
import { splitPrompt } from '@/lib/promptKeywords';

const props = defineProps<{ prompt: string }>();

const parts = computed(() => splitPrompt(props.prompt));
</script>

<template>
    <!--
        No whitespace between the parts, and the square brackets of a keyword
        stay in the text at zero size: they are not seen, but selecting and
        copying the prompt still gives back exactly what was typed.
    -->
    <span
        ><template v-for="(part, index) in parts" :key="index"
            ><span
                v-if="part.keyword"
                class="rounded border px-1 py-px font-mono text-[0.8em] whitespace-nowrap"
                :class="part.keyword.classes"
                :title="part.keyword.hint"
                data-test="prompt-keyword"
                ><span class="text-[0px]">[</span>{{ part.text.slice(1, -1)
                }}<span class="text-[0px]">]</span></span
            ><template v-else>{{ part.text }}</template></template
        ></span
    >
</template>
