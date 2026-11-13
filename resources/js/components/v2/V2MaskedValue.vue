<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';

/**
 * Version 2 masked value: a sensitive value (a bank account number, an identity number) that stays masked until
 * someone presses Show.
 *
 * The block holds NO value. The page passes `masked` (what the server already sent, for example "•••• 1234") and a
 * `reveal` function that calls the page's own AUDITED server Show and resolves with the value. The server records the
 * view first and only then returns the value; if the audit row cannot be written, `reveal` rejects, the block shows an
 * error and NOTHING is shown. The value is never put in a title, an attribute or the page props, and it never belongs
 * in lists or search: use this only on a detail screen.
 *
 * The outline button is named for what it shows ("Show bank account number", then "Hide bank account number"). The
 * value hides again after 60 seconds (`hideAfterMs`), on Hide, and when the page starts to navigate or is left. A
 * polite status says "Shown" or "Hidden". A request still running when it is hidden or left is thrown away.
 */
const props = withDefaults(
    defineProps<{
        label: string;
        masked: string;
        reveal: () => Promise<string>;
        hideAfterMs?: number;
        errorText?: string;
    }>(),
    {
        hideAfterMs: 60000,
        errorText: "Couldn't show this. Nothing was shown.",
    },
);

const value = ref<string | null>(null);
const busy = ref(false);
const failed = ref(false);
const status = ref('');
let timer: ReturnType<typeof setTimeout> | null = null;
let ticket = 0;

const hide = (announce = true) => {
    ticket++;

    if (timer) {
        clearTimeout(timer);
        timer = null;
    }

    busy.value = false;

    if (value.value !== null) {
        value.value = null;

        if (announce) {
            status.value = 'Hidden';
        }
    }
};

const show = async () => {
    if (busy.value) {
        return;
    }

    failed.value = false;
    busy.value = true;
    status.value = '';
    const mine = ++ticket;

    try {
        const result = await props.reveal();

        if (mine !== ticket) {
            return;
        }

        value.value = result;
        busy.value = false;
        status.value = 'Shown';
        timer = setTimeout(() => hide(), props.hideAfterMs);
    } catch {
        if (mine !== ticket) {
            return;
        }

        value.value = null;
        busy.value = false;
        failed.value = true;
    }
};

const onNavigate = () => hide(false);

document.addEventListener('inertia:start', onNavigate);

onBeforeUnmount(() => {
    document.removeEventListener('inertia:start', onNavigate);
    hide(false);
});
</script>

<template>
    <div data-slot="v2-masked-value" class="flex min-w-0 flex-col gap-1.5">
        <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2">
            <span
                data-private
                class="min-w-0 font-mono text-[15px] break-all text-foreground"
                >{{ value ?? masked }}</span
            >
            <button
                type="button"
                :aria-label="`${value !== null ? 'Hide' : 'Show'} ${label}`"
                :aria-busy="busy || undefined"
                :aria-disabled="busy || undefined"
                class="inline-flex h-11 shrink-0 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background aria-disabled:cursor-wait lg:h-9"
                @click="value !== null ? hide() : show()"
            >
                {{ busy ? 'Showing…' : value !== null ? 'Hide' : 'Show' }}
            </button>
        </div>
        <p v-if="failed" role="alert" class="text-sm text-destructive">
            {{ errorText }}
        </p>
        <p class="sr-only" role="status" aria-live="polite">{{ status }}</p>
    </div>
</template>
