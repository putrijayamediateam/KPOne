<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import { Button } from '@/components/ui/button';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { PageHeader } from '@/components/ui/page-header';
import { formatDateTime } from '@/lib/presentation';

type ConnectedApp = {
    clientId: string;
    name: string;
    connectedAt: string | null;
    lastAskedAt: string | null;
};

defineProps<{
    apps: ConnectedApp[];
    questions: string[];
    connectorEnabled: boolean;
}>();

const confirming = ref<ConnectedApp | null>(null);
const processing = ref(false);
const error = ref('');
const heading = ref<HTMLElement | null>(null);

const closeDialog = (open: boolean) => {
    if (!open) {
        confirming.value = null;
    }
};

const disconnect = () => {
    const app = confirming.value;

    if (!app) {
        return;
    }

    processing.value = true;
    router.delete(
        `/agents/connected-apps/${encodeURIComponent(app.clientId)}`,
        {
            preserveScroll: true,
            onSuccess: async () => {
                confirming.value = null;
                await nextTick();
                heading.value?.focus();
            },
            onError: () => {
                error.value = 'This app was not disconnected. Try again.';
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
};
</script>

<template>
    <Head title="Connected apps" />
    <main
        class="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 px-4 py-6 md:px-6"
    >
        <div ref="heading" tabindex="-1" class="outline-none">
            <PageHeader
                title="Connected apps"
                description="Apps you have allowed to ask KPOne questions."
            />
        </div>

        <p
            v-if="!connectorEnabled"
            class="text-sm text-muted-foreground"
            data-test="connector-off-note"
        >
            The connector is switched off on this server.
        </p>

        <div
            v-if="apps.length === 0"
            class="rounded-xl border border-dashed p-5 text-sm text-muted-foreground"
            data-test="connected-apps-empty"
        >
            No apps are connected. To connect Claude, start from its Connectors
            settings, then allow it on the KPOne screen that opens.
        </div>

        <ul v-else class="flex flex-col gap-4">
            <li
                v-for="app in apps"
                :key="app.clientId"
                class="rounded-xl border bg-card p-4"
                :data-test="`connected-app-${app.clientId}`"
            >
                <div
                    class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold break-words">
                            {{ app.name }}
                        </h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Connected on {{ formatDateTime(app.connectedAt) }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            <template v-if="app.lastAskedAt">
                                Last asked {{ formatDateTime(app.lastAskedAt) }}
                            </template>
                            <template v-else>Not asked yet</template>
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        class="min-h-11 w-full sm:w-auto"
                        :aria-label="`Disconnect ${app.name}`"
                        @click="
                            error = '';
                            confirming = app;
                        "
                    >
                        Disconnect
                    </Button>
                </div>
                <p class="mt-3 text-sm">
                    <span class="font-medium">What it can see:</span>
                    totals only: clinic day, money, sales, stock, Panel claims
                    and the queue. No patient names or contact details.
                </p>
                <details class="mt-2 text-sm">
                    <summary
                        class="min-h-11 cursor-pointer py-2 text-muted-foreground"
                    >
                        See the {{ questions.length }} questions
                    </summary>
                    <ul class="list-disc space-y-1 pl-5">
                        <li v-for="question in questions" :key="question">
                            {{ question }}
                        </li>
                    </ul>
                </details>
            </li>
        </ul>

        <OperationalConfirmDialog
            :open="confirming !== null"
            :title="`Disconnect ${confirming?.name ?? 'this app'}?`"
            :description="`${confirming?.name ?? 'The app'} will stop being able to ask KPOne questions at once. You can connect again later from the app. ${confirming?.name ?? 'The app'} may still list KPOne as connected until it next asks.`"
            confirm-label="Disconnect"
            :processing="processing"
            destructive
            :error="error"
            @update:open="closeDialog"
            @confirm="disconnect"
        />
    </main>
</template>
