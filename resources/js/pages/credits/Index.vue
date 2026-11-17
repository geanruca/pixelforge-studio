<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { exactUsd, formatUsd } from '@/lib/money';
import { index as credits, store } from '@/routes/credits';

type Entry = {
    id: number;
    type: 'purchase' | 'hold' | 'hold_release' | 'charge';
    label: string;
    generation_id: number | null;
    amount_nanos: number;
    balance_after_nanos: number;
    created_at: string;
};

defineProps<{
    packs: number[];
    entries: Entry[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Credits', href: credits() }],
    },
});

const page = usePage();
const balance = computed(() => page.props.auth.user.balance_nanos);
const buying = ref<number | null>(null);

const buy = (pack: number): void => {
    if (buying.value !== null) {
        return;
    }

    buying.value = pack;

    // One reference per click: if the request is sent twice, it is credited once.
    router.post(
        store.url(),
        { pack, reference: crypto.randomUUID() },
        {
            preserveScroll: true,
            onFinish: () => (buying.value = null),
        },
    );
};

const formatDate = (iso: string): string =>
    new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'medium',
    }).format(new Date(iso));
</script>

<template>
    <Head title="Credits" />

    <div class="mx-auto w-full max-w-4xl space-y-8 p-4 md:p-6">
        <Heading
            title="Credits"
            description="Credits are US dollars. Generations are paid from this balance."
        />

        <section class="rounded-xl border p-6">
            <p class="text-sm text-muted-foreground">Available credits</p>
            <p
                class="text-4xl font-semibold tabular-nums"
                :title="exactUsd(balance)"
                data-test="balance"
            >
                {{ formatUsd(balance, 'down') }}
            </p>
            <p class="mt-1 text-xs text-muted-foreground">
                Exact balance {{ exactUsd(balance) }}. Amounts reserved for
                generations in progress are not included.
            </p>

            <div class="mt-6 flex flex-wrap gap-3">
                <Button
                    v-for="pack in packs"
                    :key="pack"
                    :disabled="buying !== null"
                    :data-test="`buy-${pack}`"
                    @click="buy(pack)"
                >
                    <Spinner v-if="buying === pack" />
                    Buy ${{ pack }} of credits
                </Button>
            </div>
            <p class="mt-3 text-xs text-muted-foreground">
                Demo checkout: no payment is taken, the credits are simply
                added.
            </p>
        </section>

        <section>
            <h3 class="mb-3 text-base font-medium">Recent activity</h3>

            <p
                v-if="entries.length === 0"
                class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                Nothing yet. Buy credits to start generating.
            </p>

            <div v-else class="overflow-x-auto rounded-xl border">
                <table class="w-full text-left text-sm">
                    <thead
                        class="border-b bg-muted/50 text-xs text-muted-foreground"
                    >
                        <tr>
                            <th scope="col" class="px-4 py-2 font-medium">
                                When
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                What
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2 text-right font-medium"
                            >
                                Amount
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2 text-right font-medium"
                            >
                                Balance
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="entry in entries"
                            :key="entry.id"
                            class="border-b last:border-0"
                        >
                            <td
                                class="px-4 py-2 whitespace-nowrap text-muted-foreground"
                            >
                                {{ formatDate(entry.created_at) }}
                            </td>
                            <td class="px-4 py-2">
                                {{ entry.label }}
                                <span
                                    v-if="entry.generation_id"
                                    class="text-muted-foreground"
                                >
                                    #{{ entry.generation_id }}
                                </span>
                            </td>
                            <td
                                class="px-4 py-2 text-right tabular-nums"
                                :class="
                                    entry.amount_nanos < 0
                                        ? ''
                                        : 'text-emerald-700 dark:text-emerald-400'
                                "
                                :title="exactUsd(entry.amount_nanos)"
                            >
                                {{ entry.amount_nanos < 0 ? '−' : '+'
                                }}{{ formatUsd(Math.abs(entry.amount_nanos)) }}
                            </td>
                            <td
                                class="px-4 py-2 text-right tabular-nums"
                                :title="exactUsd(entry.balance_after_nanos)"
                            >
                                {{
                                    formatUsd(entry.balance_after_nanos, 'down')
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
