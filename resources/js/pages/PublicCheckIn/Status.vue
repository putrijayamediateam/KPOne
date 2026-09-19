<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CheckCircle2, Clock3, RefreshCw, ShieldAlert } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{
    status: {
        state: string;
        message: string;
        branch: string;
        queueNumber: string | null;
    };
}>();
const refreshing = ref(false);
let timer: number | undefined;
const terminal = computed(() =>
    ['accepted', 'rejected', 'expired'].includes(props.status.state),
);
const refresh = () => {
    if (refreshing.value) {
        return;
    }

    refreshing.value = true;
    router.reload({
        only: ['status'],
        onFinish: () => (refreshing.value = false),
    });
};
onMounted(() => {
    if (!terminal.value) {
        timer = window.setInterval(refresh, 10_000);
    }
});
onBeforeUnmount(() => {
    if (timer) {
        window.clearInterval(timer);
    }
});
</script>

<template>
    <Head title="Status pendaftaran"
        ><meta name="referrer" content="no-referrer"
    /></Head>
    <main class="mx-auto grid min-h-svh max-w-xl place-items-center px-4 py-8">
        <section
            class="w-full space-y-6 rounded-3xl border bg-white p-6 shadow-xl sm:p-9 dark:bg-zinc-900"
            aria-live="polite"
        >
            <header class="flex items-center gap-3">
                <img
                    src="/kp-mark.png"
                    alt="Klinik Putrijaya"
                    class="size-11 object-contain"
                />
                <div>
                    <p class="text-sm font-semibold text-pink-700">
                        KPOne · Klinik Putrijaya
                    </p>
                    <p class="text-sm text-zinc-600">{{ status.branch }}</p>
                </div>
            </header>
            <div class="space-y-4 text-center">
                <div
                    class="mx-auto grid size-16 place-items-center rounded-full"
                    :class="
                        status.state === 'accepted'
                            ? 'bg-emerald-100 text-emerald-700'
                            : status.state === 'rejected' ||
                                status.state === 'expired'
                              ? 'bg-amber-100 text-amber-700'
                              : 'bg-pink-100 text-pink-700'
                    "
                >
                    <CheckCircle2
                        v-if="status.state === 'accepted'"
                        class="size-8"
                    />
                    <ShieldAlert
                        v-else-if="
                            status.state === 'rejected' ||
                            status.state === 'expired'
                        "
                        class="size-8"
                    />
                    <Clock3 v-else class="size-8" />
                </div>
                <div v-if="status.queueNumber" class="space-y-1">
                    <p class="text-sm font-medium text-zinc-500">
                        Nombor queue anda
                    </p>
                    <p
                        class="text-6xl font-bold tracking-tight text-emerald-700"
                    >
                        {{ status.queueNumber }}
                    </p>
                </div>
                <h1 v-else class="text-2xl font-semibold">
                    Status pendaftaran
                </h1>
                <p
                    class="mx-auto max-w-sm leading-7 text-zinc-600 dark:text-zinc-300"
                >
                    {{ status.message }}
                </p>
            </div>
            <button
                v-if="!terminal"
                type="button"
                class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl border font-semibold"
                :disabled="refreshing"
                @click="refresh"
            >
                <RefreshCw
                    class="size-5"
                    :class="{ 'animate-spin': refreshing }"
                />Semak status
            </button>
            <p class="text-center text-xs text-zinc-500">
                Halaman ini tidak menyimpan maklumat anda dalam storan pelayar.
            </p>
        </section>
    </main>
</template>
