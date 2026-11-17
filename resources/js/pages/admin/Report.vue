<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, TriangleAlert } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { exactUsd } from '@/lib/money';
import { report as reportRoute } from '@/routes/admin';

type Sums = {
    generations: number;
    revenue_nanos: number;
    provider_cost_nanos: number;
    profit_nanos: number;
};

type Reconciliation = {
    pixelforge_generations: number | null;
    pixelforge_cost_nanos: number | null;
    matches: boolean | null;
};

type UserRow = Sums & { id: number; name: string; email: string };
type DayRow = Sums & Reconciliation & { date: string; users: UserRow[] };

const props = defineProps<{
    report: {
        start_date: string;
        end_date: string;
        days: DayRow[];
        totals: Sums & Reconciliation;
        pixelforge_available: boolean;
    };
    operations: {
        provider: { name: string; healthy: boolean; retry_in_seconds: number };
        slots: { in_use: number; total: number; waiting: number };
        stuck: number;
        failures: { code: string; count: number }[];
        failure_window_hours: number;
    };
    maxRangeDays: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Admin report', href: reportRoute() }],
    },
});

const startDate = ref(props.report.start_date);
const endDate = ref(props.report.end_date);

const applyRange = (): void => {
    router.get(
        reportRoute.url(),
        { start_date: startDate.value, end_date: endDate.value },
        { preserveState: true, preserveScroll: true },
    );
};
</script>

<template>
    <Head title="Admin report" />

    <div class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
        <Heading
            title="Spend, cost and profit"
            description="What users were charged, what Pixelforge billed us and the difference. Days are UTC days of completion, the same day Pixelforge bills on. Amounts are exact."
        />

        <section aria-labelledby="operations-heading" data-test="operations">
            <h3 id="operations-heading" class="mb-3 text-base font-medium">
                Right now
            </h3>
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border p-4">
                    <dt class="text-sm text-muted-foreground">Image service</dt>
                    <dd
                        class="text-lg font-semibold"
                        :class="
                            operations.provider.healthy
                                ? 'text-emerald-700 dark:text-emerald-400'
                                : 'text-red-700 dark:text-red-400'
                        "
                    >
                        {{
                            operations.provider.healthy
                                ? 'Healthy'
                                : `Paused, retry in ${operations.provider.retry_in_seconds}s`
                        }}
                    </dd>
                    <dd class="text-xs text-muted-foreground">
                        Circuit breaker on {{ operations.provider.name }}
                    </dd>
                </div>
                <div class="rounded-xl border p-4">
                    <dt class="text-sm text-muted-foreground">Slots in use</dt>
                    <dd class="text-lg font-semibold tabular-nums">
                        {{ operations.slots.in_use }} of
                        {{ operations.slots.total }}
                    </dd>
                    <dd class="text-xs text-muted-foreground">
                        {{ operations.slots.waiting }} waiting in line
                    </dd>
                </div>
                <div class="rounded-xl border p-4">
                    <dt class="text-sm text-muted-foreground">
                        Possibly stuck
                    </dt>
                    <dd
                        class="text-lg font-semibold tabular-nums"
                        :class="{
                            'text-red-700 dark:text-red-400':
                                operations.stuck > 0,
                        }"
                    >
                        {{ operations.stuck }}
                    </dd>
                    <dd class="text-xs text-muted-foreground">
                        Unfinished for over ten minutes
                    </dd>
                </div>
                <div class="rounded-xl border p-4">
                    <dt class="text-sm text-muted-foreground">
                        Failures, last
                        {{ operations.failure_window_hours }} hours
                    </dt>
                    <dd
                        v-if="operations.failures.length === 0"
                        class="text-lg font-semibold"
                    >
                        None
                    </dd>
                    <dd v-else>
                        <ul class="mt-1 space-y-0.5 text-sm">
                            <li
                                v-for="failure in operations.failures"
                                :key="failure.code"
                                class="flex justify-between gap-2"
                            >
                                <code class="truncate font-mono text-xs">{{
                                    failure.code
                                }}</code>
                                <span class="tabular-nums">{{
                                    failure.count
                                }}</span>
                            </li>
                        </ul>
                    </dd>
                </div>
            </dl>
        </section>

        <form
            class="flex flex-wrap items-end gap-3"
            @submit.prevent="applyRange"
        >
            <div class="grid gap-1.5">
                <Label for="start_date">From (UTC)</Label>
                <Input
                    id="start_date"
                    v-model="startDate"
                    type="date"
                    :max="endDate"
                    required
                />
            </div>
            <div class="grid gap-1.5">
                <Label for="end_date">To (UTC)</Label>
                <Input
                    id="end_date"
                    v-model="endDate"
                    type="date"
                    :min="startDate"
                    required
                />
            </div>
            <Button type="submit" variant="outline">Apply</Button>
            <p class="text-xs text-muted-foreground">
                At most {{ maxRangeDays }} days.
            </p>
        </form>

        <Alert v-if="!report.pixelforge_available">
            <TriangleAlert aria-hidden="true" />
            <AlertTitle>Pixelforge usage report unavailable</AlertTitle>
            <AlertDescription>
                Our own numbers are shown, but they could not be checked against
                Pixelforge right now.
            </AlertDescription>
        </Alert>
        <Alert
            v-else-if="report.totals.matches === false"
            variant="destructive"
        >
            <CircleAlert aria-hidden="true" />
            <AlertTitle>Provider cost does not match Pixelforge</AlertTitle>
            <AlertDescription>
                We recorded
                {{ exactUsd(report.totals.provider_cost_nanos) }} for
                {{ report.totals.generations }} generation(s); Pixelforge billed
                {{ exactUsd(report.totals.pixelforge_cost_nanos ?? 0) }} for
                {{ report.totals.pixelforge_generations }}. Check the days
                flagged below.
            </AlertDescription>
        </Alert>
        <Alert v-else>
            <CircleCheck aria-hidden="true" />
            <AlertTitle>Provider cost matches Pixelforge exactly</AlertTitle>
            <AlertDescription>
                Every day in this range equals Pixelforge's usage report to the
                nanodollar.
            </AlertDescription>
        </Alert>

        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border p-4">
                <dt class="text-sm text-muted-foreground">
                    Generations billed
                </dt>
                <dd class="text-2xl font-semibold tabular-nums">
                    {{ report.totals.generations }}
                </dd>
            </div>
            <div class="rounded-xl border p-4">
                <dt class="text-sm text-muted-foreground">Credit spend</dt>
                <dd class="text-2xl font-semibold tabular-nums">
                    {{ exactUsd(report.totals.revenue_nanos) }}
                </dd>
            </div>
            <div class="rounded-xl border p-4">
                <dt class="text-sm text-muted-foreground">Provider cost</dt>
                <dd class="text-2xl font-semibold tabular-nums">
                    {{ exactUsd(report.totals.provider_cost_nanos) }}
                </dd>
            </div>
            <div class="rounded-xl border p-4">
                <dt class="text-sm text-muted-foreground">Profit</dt>
                <dd class="text-2xl font-semibold tabular-nums">
                    {{ exactUsd(report.totals.profit_nanos) }}
                </dd>
            </div>
        </dl>

        <p
            v-if="report.days.length === 0"
            class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
        >
            No billed generations in this range.
        </p>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full text-left text-sm">
                <thead
                    class="border-b bg-muted/50 text-xs text-muted-foreground"
                >
                    <tr>
                        <th scope="col" class="px-4 py-2 font-medium">
                            Day (UTC) / user
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-2 text-right font-medium"
                        >
                            Generations
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-2 text-right font-medium"
                        >
                            Credit spend
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-2 text-right font-medium"
                        >
                            Provider cost
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-2 text-right font-medium"
                        >
                            Profit
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-2 text-right font-medium"
                        >
                            Pixelforge billed
                        </th>
                    </tr>
                </thead>
                <tbody
                    v-for="day in report.days"
                    :key="day.date"
                    class="border-b last:border-0"
                >
                    <tr
                        class="bg-muted/30 font-medium"
                        :data-test="`day-${day.date}`"
                    >
                        <th scope="row" class="px-4 py-2 font-medium">
                            {{ day.date }}
                        </th>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ day.generations }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ exactUsd(day.revenue_nanos) }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ exactUsd(day.provider_cost_nanos) }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ exactUsd(day.profit_nanos) }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            <template v-if="day.pixelforge_cost_nanos === null"
                                >not checked</template
                            >
                            <span
                                v-else
                                class="inline-flex items-center justify-end gap-1"
                                :class="
                                    day.matches
                                        ? 'text-emerald-700 dark:text-emerald-400'
                                        : 'text-red-700 dark:text-red-400'
                                "
                            >
                                <component
                                    :is="
                                        day.matches ? CircleCheck : CircleAlert
                                    "
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                {{ exactUsd(day.pixelforge_cost_nanos) }}
                                ({{ day.pixelforge_generations }})
                                <span class="sr-only">{{
                                    day.matches ? 'matches' : 'does not match'
                                }}</span>
                            </span>
                        </td>
                    </tr>
                    <tr v-for="user in day.users" :key="user.id">
                        <td class="py-2 pr-4 pl-8">
                            {{ user.name }}
                            <span class="text-muted-foreground">{{
                                user.email
                            }}</span>
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ user.generations }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ exactUsd(user.revenue_nanos) }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ exactUsd(user.provider_cost_nanos) }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ exactUsd(user.profit_nanos) }}
                        </td>
                        <td class="px-4 py-2"></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
