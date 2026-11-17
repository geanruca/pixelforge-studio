<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, FileCode2, FlaskConical } from '@lucide/vue';
import { computed } from 'vue';
import { dashboard, login } from '@/routes';

type Note = {
    title: string;
    problem: string;
    decision: string;
    files: string[];
    tests: string[];
};

type Area = { area: string; summary: string; notes: Note[] };

const props = defineProps<{
    areas: Area[];
    repository: string;
}>();

const page = usePage();
const signedIn = computed(() => Boolean(page.props.auth?.user));

const total = computed(() =>
    props.areas.reduce((count, area) => count + area.notes.length, 0),
);

const anchor = (area: string): string =>
    area.toLowerCase().replace(/[^a-z]+/g, '-');

const source = (path: string): string =>
    `${props.repository}/blob/main/${path}`;

const fileName = (path: string): string => path.split('/').pop() ?? path;
</script>

<template>
    <Head title="Engineering notes" />

    <div class="min-h-screen bg-background text-foreground">
        <header class="border-b">
            <div
                class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3 px-4 py-4 md:px-6"
            >
                <p class="font-semibold">Pixelforge Studio</p>
                <nav class="flex items-center gap-4 text-sm">
                    <a
                        :href="repository"
                        class="text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                    >
                        Source on GitHub
                    </a>
                    <Link
                        :href="signedIn ? dashboard() : login()"
                        class="inline-flex items-center gap-1 rounded-md bg-primary px-3 py-1.5 font-medium text-primary-foreground"
                    >
                        {{ signedIn ? 'Open the app' : 'Sign in' }}
                        <ArrowRight class="size-4" aria-hidden="true" />
                    </Link>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-4 py-10 md:px-6">
            <h1 class="text-3xl font-semibold tracking-tight">
                Engineering notes
            </h1>
            <p class="mt-3 max-w-2xl text-muted-foreground">
                The brief asked for image generation with correct billing. These
                are the {{ total }} decisions behind it: the problem each one
                answers, what was decided, and the file and test that back it.
                Every link below is checked by a test, so this page cannot go
                stale.
            </p>

            <nav class="mt-6 flex flex-wrap gap-2 text-sm" aria-label="Areas">
                <a
                    v-for="area in areas"
                    :key="area.area"
                    :href="`#${anchor(area.area)}`"
                    class="rounded-full border px-3 py-1 hover:bg-accent"
                >
                    {{ area.area }}
                    <span class="text-muted-foreground">
                        {{ area.notes.length }}
                    </span>
                </a>
            </nav>

            <section
                v-for="area in areas"
                :id="anchor(area.area)"
                :key="area.area"
                class="mt-12 scroll-mt-6"
            >
                <h2 class="text-xl font-semibold tracking-tight">
                    {{ area.area }}
                </h2>
                <p class="mt-1 text-muted-foreground">{{ area.summary }}</p>

                <div class="mt-5 grid gap-4 md:grid-cols-2">
                    <article
                        v-for="note in area.notes"
                        :key="note.title"
                        class="flex flex-col rounded-xl border p-5"
                    >
                        <h3 class="font-semibold">{{ note.title }}</h3>

                        <dl class="mt-3 space-y-3 text-sm">
                            <div>
                                <dt
                                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                                >
                                    Problem
                                </dt>
                                <dd class="mt-0.5">{{ note.problem }}</dd>
                            </div>
                            <div>
                                <dt
                                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                                >
                                    Decision
                                </dt>
                                <dd class="mt-0.5">{{ note.decision }}</dd>
                            </div>
                        </dl>

                        <ul
                            class="mt-auto flex flex-wrap gap-1.5 pt-4 text-xs"
                            aria-label="Code and tests"
                        >
                            <li v-for="path in note.files" :key="path">
                                <a
                                    :href="source(path)"
                                    :title="path"
                                    class="inline-flex items-center gap-1 rounded border px-1.5 py-0.5 font-mono hover:bg-accent"
                                >
                                    <FileCode2
                                        class="size-3"
                                        aria-hidden="true"
                                    />
                                    {{ fileName(path) }}
                                </a>
                            </li>
                            <li v-for="path in note.tests" :key="path">
                                <a
                                    :href="source(path)"
                                    :title="path"
                                    class="inline-flex items-center gap-1 rounded border border-emerald-300 bg-emerald-50 px-1.5 py-0.5 font-mono text-emerald-900 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200"
                                >
                                    <FlaskConical
                                        class="size-3"
                                        aria-hidden="true"
                                    />
                                    {{ fileName(path) }}
                                </a>
                            </li>
                        </ul>
                    </article>
                </div>
            </section>
        </main>
    </div>
</template>
