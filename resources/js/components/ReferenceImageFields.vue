<script setup lang="ts">
import { Plus, X } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    max: number;
    errors: Record<string, string | undefined>;
}>();

const urls = defineModel<string[]>({ required: true });

const canAdd = computed(() => props.max > urls.value.length);

const add = (): void => {
    if (canAdd.value) {
        urls.value = [...urls.value, ''];
    }
};

const update = (index: number, event: Event): void => {
    urls.value = urls.value.map((url, position) =>
        position === index ? (event.target as HTMLInputElement).value : url,
    );
};

const remove = (index: number): void => {
    urls.value = urls.value.filter((_, position) => position !== index);
};

const errorFor = (index: number): string | undefined =>
    props.errors[`reference_images.${index}`];
</script>

<template>
    <div class="grid gap-2">
        <div v-for="(url, index) in urls" :key="index" class="grid gap-1">
            <div class="flex gap-2">
                <input
                    :value="url"
                    type="url"
                    inputmode="url"
                    placeholder="https://example.com/reference.png"
                    class="h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive"
                    :aria-label="'Reference image URL ' + (index + 1)"
                    :aria-invalid="errorFor(index) !== undefined"
                    @input="update(index, $event)"
                />
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    :aria-label="'Remove reference image ' + (index + 1)"
                    @click="remove(index)"
                >
                    <X class="size-4" aria-hidden="true" />
                </Button>
            </div>
            <InputError :message="errorFor(index)" />
        </div>
        <Button
            v-if="canAdd"
            type="button"
            variant="outline"
            class="w-fit"
            @click="add"
        >
            <Plus class="size-4" aria-hidden="true" />
            Add a reference image
        </Button>
        <InputError :message="errors.reference_images" />
    </div>
</template>
