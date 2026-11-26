<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { LoaderCircle, X } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import RegistrationCreateForm from '@/components/registration/RegistrationCreateForm.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { JsonRequestError, requestJson } from '@/lib/json-client';
import type { PatientRegistrationSummary, VisitOptions } from '@/types';
import type { RegistrationRequirements } from '@/types/registration-settings';

type FormPayload = {
    options: VisitOptions & { idempotencyKey: string };
    recentPatients: PatientRegistrationSummary[];
    requirements: RegistrationRequirements;
    guardianToday?: string;
};
const props = defineProps<{
    open: boolean;
    branchId: number;
    canCreate: boolean;
    opener: HTMLElement | null;
}>();
const emit = defineEmits<{ 'update:open': [value: boolean]; registered: [] }>();
const payload = ref<FormPayload | null>(null);
const formRef = ref<InstanceType<typeof RegistrationCreateForm> | null>(null);
const loading = ref(false);
const loadError = ref('');
const discardOpen = ref(false);
const processing = computed(() => formRef.value?.processing === true);
const dirty = computed(() => formRef.value?.hasDraft === true);
const stale = computed(
    () =>
        !props.canCreate ||
        (payload.value !== null &&
            payload.value.options.branch.id !== props.branchId),
);
let request: AbortController | undefined;
let generation = 0;
let disposed = false;
let successHandled = false;
let lastField: HTMLElement | null = null;
let removeVisitGuard: (() => void) | undefined;
const abortLoad = () => {
    ++generation;
    request?.abort();
    request = undefined;
    loading.value = false;
};
const focusFirstField = () =>
    nextTick(() => {
        const field =
            document.querySelector<HTMLElement>(
                '[data-registration-dialog-body] #patient-search',
            ) ??
            document.querySelector<HTMLElement>(
                '[data-registration-dialog-body] input:not([disabled]), [data-registration-dialog-body] [role="combobox"]:not([disabled]), [data-registration-dialog-body] button:not([disabled])',
            );
        field?.focus();
    });
const load = async () => {
    abortLoad();

    if (!props.open || disposed || payload.value) {
        return;
    }

    loadError.value = '';

    if (!props.canCreate) {
        loadError.value =
            'Registration is no longer available for this account.';

        return;
    }

    const current = generation;
    const branch = props.branchId;
    const controller = new AbortController();
    request = controller;
    loading.value = true;

    try {
        const result = await requestJson<FormPayload>('/registration/create', {
            signal: controller.signal,
        });

        if (
            current !== generation ||
            controller.signal.aborted ||
            disposed ||
            !props.open
        ) {
            return;
        }

        if (
            !props.canCreate ||
            branch !== props.branchId ||
            result.options?.branch?.id !== branch
        ) {
            loadError.value =
                'The active branch changed. Close this dialog and open a new registration.';

            return;
        }

        if (
            !Array.isArray(result.options.doctors) ||
            !Array.isArray(result.options.panels) ||
            !Array.isArray(result.recentPatients) ||
            typeof result.options.idempotencyKey !== 'string' ||
            !result.requirements
        ) {
            loadError.value =
                'The registration form could not be loaded. Try again or use the full page.';

            return;
        }

        payload.value = result;
        await focusFirstField();
    } catch (error) {
        if (current !== generation || controller.signal.aborted || disposed) {
            return;
        }

        loadError.value =
            error instanceof JsonRequestError &&
            [401, 403, 419].includes(error.status)
                ? 'Registration access could not be confirmed. Reload the full page to check your session and branch.'
                : 'The registration form could not be loaded. Try again or use the full page.';
    } finally {
        if (current === generation) {
            loading.value = false;
            request = undefined;
        }
    }
};
const requestClose = () => {
    if (processing.value) {
        return;
    }

    if (dirty.value) {
        discardOpen.value = true;

        return;
    }

    emit('update:open', false);
};
const restoreDraftFocus = (event: Event) => {
    event.preventDefault();

    if (!props.open) {
        return;
    }

    if (lastField?.isConnected) {
        lastField.focus();
    } else {
        void focusFirstField();
    }
};
const focusKeepEditing = (event: Event) => {
    event.preventDefault();
    document
        .querySelector<HTMLElement>('[data-registration-keep-editing]')
        ?.focus();
};
const keepEditing = () => {
    discardOpen.value = false;
};
const discard = () => {
    if (processing.value) {
        return;
    }

    discardOpen.value = false;
    emit('update:open', false);
};
const registered = () => {
    if (!props.open || successHandled) {
        return;
    }

    successHandled = true;
    emit('registered');
};
const returnFocus = (event: Event) => {
    event.preventDefault();
    void nextTick(() => {
        if (props.opener?.isConnected) {
            props.opener.focus();

            return;
        }

        const heading = document.querySelector<HTMLElement>('main h1');

        if (heading) {
            heading.setAttribute('tabindex', '-1');
            heading.focus();
        }
    });
};
const rememberField = (event: FocusEvent) => {
    if (event.target instanceof HTMLElement) {
        lastField = event.target;
    }
};
const warnBeforeUnload = (event: BeforeUnloadEvent) => {
    if (props.open && (dirty.value || processing.value)) {
        event.preventDefault();
    }
};
onMounted(() => {
    window.addEventListener('beforeunload', warnBeforeUnload);
    removeVisitGuard = router.on('before', (event) => {
        if (!props.open) {
            return;
        }

        const visit = event.detail.visit;

        if (
            visit.method === 'post' &&
            visit.url.pathname === '/registration' &&
            (visit.data as Record<string, unknown>).idempotency_key ===
                formRef.value?.commandKey
        ) {
            return;
        }

        if (processing.value) {
            return false;
        }

        if (dirty.value) {
            return window.confirm(
                'Leave Registration and discard this local draft? This does not undo a registration that may already have been saved.',
            );
        }
    });
});
watch(
    () => props.open,
    (open) => {
        if (open) {
            successHandled = false;
            void load();
        } else {
            abortLoad();
            payload.value = null;
            loadError.value = '';
            discardOpen.value = false;
            lastField = null;
        }
    },
    { immediate: true },
);
watch(
    () => [props.branchId, props.canCreate],
    () => {
        if (props.open && !payload.value) {
            void load();
        }
    },
);
onBeforeUnmount(() => {
    disposed = true;
    abortLoad();
    removeVisitGuard?.();
    window.removeEventListener('beforeunload', warnBeforeUnload);
});
</script>

<template>
    <Dialog
        :open="open"
        @update:open="
            (value) => {
                if (!value) requestClose();
            }
        "
    >
        <DialogContent
            :show-close-button="false"
            class="grid max-h-[calc(100dvh-2rem)] grid-rows-[auto_minmax(0,1fr)] gap-4 p-4 sm:max-w-[760px] sm:p-6"
            @pointer-down-outside.prevent
            @escape-key-down="
                (event) => {
                    event.preventDefault();
                    if (!discardOpen) requestClose();
                }
            "
            @close-auto-focus="returnFocus"
        >
            <DialogHeader class="relative pr-12">
                <DialogTitle>Register Visit</DialogTitle>
                <DialogDescription
                    >{{ payload?.options.branch.name ?? 'Current branch' }} ·
                    Review the patient and visit before
                    registering.</DialogDescription
                >
                <Button
                    type="button"
                    variant="ghost"
                    class="absolute top-0 right-0 size-11 p-0 lg:size-9"
                    aria-label="Close registration"
                    :disabled="processing"
                    @click="requestClose"
                    ><X class="size-4"
                /></Button>
            </DialogHeader>
            <div
                data-registration-dialog-body
                class="flex min-h-0 flex-col gap-4 overflow-y-auto p-1"
                @focusin="rememberField"
            >
                <p
                    v-if="loading"
                    role="status"
                    class="flex items-center gap-2 py-8 text-sm text-muted-foreground"
                >
                    <LoaderCircle class="size-4 animate-spin" /> Loading
                    registration…
                </p>
                <div
                    v-else-if="loadError"
                    role="alert"
                    class="space-y-3 rounded-lg border p-4 text-sm"
                >
                    <p>{{ loadError }}</p>
                    <div class="flex flex-wrap gap-2">
                        <Button v-if="canCreate" variant="outline" @click="load"
                            >Try again</Button
                        ><Button variant="outline" as-child
                            ><Link href="/registration/create"
                                >Open full page</Link
                            ></Button
                        >
                    </div>
                </div>
                <p
                    v-if="payload && stale"
                    role="alert"
                    class="rounded-lg border border-warning/25 bg-warning/10 p-3 text-sm text-warning-strong"
                >
                    The branch or registration access changed. This draft cannot
                    be submitted here. Discard it and open a new registration in
                    the current branch.
                </p>
                <RegistrationCreateForm
                    v-if="payload"
                    ref="formRef"
                    v-bind="payload"
                    presentation="dialog"
                    :blocked="stale"
                    @registered="registered"
                />
            </div>
        </DialogContent>
    </Dialog>
    <Dialog
        :open="discardOpen"
        @update:open="
            (value) => {
                if (!value && !processing) keepEditing();
            }
        "
    >
        <DialogContent
            :show-close-button="!processing"
            class="gap-5 p-5 sm:max-w-md"
            @close-auto-focus="restoreDraftFocus"
            @open-auto-focus="focusKeepEditing"
            @escape-key-down="
                (event) => {
                    if (processing) event.preventDefault();
                }
            "
            @pointer-down-outside.prevent
        >
            <DialogHeader>
                <DialogTitle>Discard this registration draft?</DialogTitle>
                <DialogDescription>{{
                    formRef?.unconfirmed
                        ? 'The result is unconfirmed. Discarding this local draft does not undo a registration that may already have been saved. Keep editing to retry the same command.'
                        : 'The patient and visit changes in this form have not been confirmed as saved.'
                }}</DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button
                    data-registration-keep-editing
                    type="button"
                    variant="outline"
                    :disabled="processing"
                    @click="keepEditing"
                    >Keep editing</Button
                >
                <Button
                    type="button"
                    variant="destructive"
                    :disabled="processing"
                    @click="discard"
                    >Discard draft</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
