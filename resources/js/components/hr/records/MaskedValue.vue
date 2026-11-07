<script setup lang="ts">
// A masked value with an audited Show (HR-PLAN 11.3 rule 7). The value is fetched only when Show is pressed, after the
// server records the view, and is dropped again on Hide, on leaving the tab or the page. Nothing is copied.
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { csrf } from './types';

const props = defineProps<{
    label: string;
    /** What a screen reader hears for the field, e.g. "identity number". */
    field: string;
    /** The visible mask, e.g. "IC •••• •••• 1234", or "Hidden". */
    mask: string;
    /** Last four characters, for screen readers. */
    last4?: string | null;
    workerId: string;
    showField: string;
    extra?: Record<string, string>;
    /** Changes when the tab changes, so a shown value hides again. */
    resetKey?: string;
    /** HR-5a: another audited Show endpoint (a credential reference); defaults to the staff file's. */
    url?: string;
}>();

const emit = defineEmits<{ shown: [] }>();
const value = ref<string | null>(null);
// A response that arrives after Hide (or after the page was hidden) is dropped (Suresh, M3).
let generation = 0;
const shownAt = ref('');
const error = ref('');
const busy = ref(false);

const spoken = computed(() =>
    props.last4
        ? `${props.label}, ending ${props.last4.split('').join(' ')}, hidden`
        : `${props.label}, hidden`,
);

const hide = () => {
    generation++;
    value.value = null;
    shownAt.value = '';
};

const show = async () => {
    if (busy.value) {
        return;
    }

    busy.value = true;
    error.value = '';
    const mine = ++generation;

    try {
        const response = await fetch(
            props.url ?? `/hr/records/${props.workerId}/show-value`,
            {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({
                    field: props.showField,
                    ...(props.extra ?? {}),
                }),
            },
        );
        const data = (await response.json().catch(() => ({}))) as {
            value?: string;
            shownAt?: string;
            errors?: Record<string, string>;
        };

        if (mine !== generation) {
            return;
        }

        if (!response.ok || typeof data.value !== 'string') {
            error.value =
                Object.values(data.errors ?? {})[0] ??
                'This could not be shown. Try again.';

            return;
        }

        value.value = data.value;
        shownAt.value = new Intl.DateTimeFormat('en-US', {
            hour: 'numeric',
            minute: '2-digit',
            hour12: true,
        }).format(new Date(data.shownAt ?? Date.now()));
        emit('shown');
    } catch {
        error.value = 'This could not be shown. Try again.';
    } finally {
        busy.value = false;
    }
};

watch(() => props.resetKey, hide);
const onVisibility = () => {
    if (document.visibilityState === 'hidden') {
        hide();
    }
};
document.addEventListener('visibilitychange', onVisibility);
onBeforeUnmount(() => {
    document.removeEventListener('visibilitychange', onVisibility);
    hide();
});
</script>

<template>
    <div class="flex min-w-0 flex-col gap-1" data-test="masked-value">
        <div class="flex min-w-0 flex-wrap items-center gap-2">
            <template v-if="value === null">
                <span class="font-medium tabular-nums" aria-hidden="true">{{
                    mask
                }}</span>
                <span class="sr-only">{{ spoken }}</span>
            </template>
            <span
                v-else
                class="min-w-0 font-medium break-words"
                aria-live="polite"
                data-test="shown-value"
                >{{ value }}</span
            >
            <Button
                v-if="value === null"
                type="button"
                variant="outline"
                size="sm"
                class="max-lg:min-h-11"
                :disabled="busy"
                :data-test="`show-${showField}`"
                @click="show"
            >
                {{ busy ? 'Showing…' : `Show ${field}` }}
            </Button>
            <Button
                v-else
                type="button"
                variant="outline"
                size="sm"
                class="max-lg:min-h-11"
                :data-test="`hide-${showField}`"
                @click="hide"
            >
                Hide {{ field }}
            </Button>
        </div>
        <p
            v-if="value !== null"
            class="text-xs text-muted-foreground"
            data-test="shown-note"
        >
            Shown at {{ shownAt }}. This view is recorded.
        </p>
        <p v-if="error" class="text-sm text-destructive" role="alert">
            {{ error }}
        </p>
    </div>
</template>
