<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Check, Copy, KeyRound, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy, index, store } from '@/routes/api-tokens';

type Token = {
    id: number;
    name: string;
    created_at: string | null;
    last_used_at: string | null;
    expires_at: string | null;
};

const props = defineProps<{
    tokens: Token[];
    apiUrl: string;
    mcpUrl: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'API tokens', href: index() }],
    },
});

const page = usePage();

// Present only on the response that created the token; it is never stored in plain text.
const newToken = computed(() => {
    const token = (page.flash as Record<string, unknown> | undefined)?.apiToken;

    return typeof token === 'string' ? token : null;
});

const form = useForm({ name: '' });

const create = (): void => {
    form.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};

const revoking = ref<number | null>(null);

const revoke = (token: Token): void => {
    revoking.value = token.id;
    router.delete(destroy.url(token.id), {
        preserveScroll: true,
        onFinish: () => (revoking.value = null),
    });
};

const copied = ref<string | null>(null);

const copy = async (label: string, text: string): Promise<void> => {
    await navigator.clipboard.writeText(text);
    copied.value = label;
    window.setTimeout(() => (copied.value = null), 2000);
};

const curlExample = computed(
    () =>
        `curl ${props.apiUrl}/balance \\\n  -H "Authorization: Bearer ${newToken.value ?? '<YOUR_TOKEN>'}"`,
);

const mcpExample = computed(
    () =>
        `claude mcp add --transport http pixelforge-studio ${props.mcpUrl} \\\n  --header "Authorization: Bearer ${newToken.value ?? '<YOUR_TOKEN>'}"`,
);

const formatDate = (iso: string | null): string =>
    iso === null
        ? 'never'
        : new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(iso));
</script>

<template>
    <Head title="API tokens" />

    <h1 class="sr-only">API tokens</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="API tokens"
            description="Tokens let scripts and AI agents generate images on your behalf, paid from your credits. Treat them like passwords."
        />

        <Alert v-if="newToken" data-test="new-token">
            <KeyRound aria-hidden="true" />
            <AlertTitle>Copy your new token now</AlertTitle>
            <AlertDescription>
                <p>It is shown only this once.</p>
                <div class="mt-2 flex w-full items-center gap-2">
                    <code
                        class="min-w-0 flex-1 rounded bg-muted px-2 py-1.5 font-mono text-xs break-all text-foreground"
                        >{{ newToken }}</code
                    >
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="copy('token', newToken)"
                    >
                        <component
                            :is="copied === 'token' ? Check : Copy"
                            class="size-4"
                            aria-hidden="true"
                        />
                        {{ copied === 'token' ? 'Copied' : 'Copy' }}
                    </Button>
                </div>
            </AlertDescription>
        </Alert>

        <form class="flex items-end gap-3" @submit.prevent="create">
            <div class="grid flex-1 gap-2">
                <Label for="token-name">New token name</Label>
                <Input
                    id="token-name"
                    v-model="form.name"
                    placeholder="e.g. Claude Code on my laptop"
                    maxlength="60"
                    required
                />
            </div>
            <Button type="submit" :disabled="form.processing">
                Create token
            </Button>
        </form>
        <InputError :message="form.errors.name" />

        <ul v-if="tokens.length" class="divide-y rounded-xl border">
            <li
                v-for="token in tokens"
                :key="token.id"
                class="flex items-center justify-between gap-4 px-4 py-3"
            >
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium">
                        {{ token.name }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Created {{ formatDate(token.created_at) }} · last used
                        {{ formatDate(token.last_used_at) }} · expires
                        {{ formatDate(token.expires_at) }}
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="revoking === token.id"
                    :aria-label="`Revoke token ${token.name}`"
                    @click="revoke(token)"
                >
                    <Trash2 class="size-4" aria-hidden="true" />
                    Revoke
                </Button>
            </li>
        </ul>
        <p
            v-else
            class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
        >
            No tokens yet.
        </p>

        <section class="space-y-4">
            <Heading
                variant="small"
                title="Use it"
                description="The same account, credits and prices as the web app."
            />

            <div
                v-for="example in [
                    {
                        label: 'REST API',
                        hint: `Base URL ${apiUrl}`,
                        code: curlExample,
                    },
                    {
                        label: 'MCP server (Claude Code)',
                        hint: `Endpoint ${mcpUrl}`,
                        code: mcpExample,
                    },
                ]"
                :key="example.label"
                class="rounded-xl border"
            >
                <div
                    class="flex items-center justify-between gap-2 border-b px-4 py-2"
                >
                    <div class="min-w-0">
                        <p class="text-sm font-medium">{{ example.label }}</p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ example.hint }}
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="copy(example.label, example.code)"
                    >
                        <component
                            :is="copied === example.label ? Check : Copy"
                            class="size-4"
                            aria-hidden="true"
                        />
                        {{ copied === example.label ? 'Copied' : 'Copy' }}
                    </Button>
                </div>
                <pre
                    class="overflow-x-auto p-4 font-mono text-xs leading-relaxed"
                ><code>{{ example.code }}</code></pre>
            </div>
        </section>
    </div>
</template>
