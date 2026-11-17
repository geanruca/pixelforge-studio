<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Coins } from '@lucide/vue';
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { exactUsd, formatUsd } from '@/lib/money';
import { index as credits } from '@/routes/credits';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const page = usePage();
const balance = computed(() => page.props.auth.user.balance_nanos);
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>
        <Link
            :href="credits()"
            class="ml-auto inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm font-medium tabular-nums transition-colors hover:bg-accent"
            :title="`Available credits: ${exactUsd(balance)}`"
            data-test="balance-pill"
        >
            <Coins class="size-4 text-muted-foreground" aria-hidden="true" />
            <span class="sr-only">Available credits:</span>
            {{ formatUsd(balance, 'down') }}
        </Link>
    </header>
</template>
