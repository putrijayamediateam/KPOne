<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Document = {
    id: number;
    number: string;
    issuedAt: string;
    status: string;
    canCorrect: boolean;
};
const props = defineProps<{
    certificates: {
        visitNumber: string;
        patientName: string;
        canFirstIssue: boolean;
        branchId: number;
        lockVersion: number;
        canIssue: boolean;
        credentialVerified: boolean;
        canPrint: boolean;
        today: string;
        documents: Document[];
        page: number;
        hasMore: boolean;
    };
}>();
const page = usePage();
const open = ref(false);
const saved = ref(false);
const loaded = ref(true);
const branchChanged = computed(
    () => props.certificates.branchId !== page.props.branchContext?.active?.id,
);
const baseUrl = computed(
    () =>
        `/visits/${encodeURIComponent(props.certificates.visitNumber)}/medical-certificates`,
);
const form = useForm({
    expected_branch_id: props.certificates.branchId,
    lock_version: props.certificates.lockVersion,
    idempotency_key: crypto.randomUUID(),
    action: 'issue',
    document_id: null as number | null,
    language: 'en',
    start_date: props.certificates.today,
    end_date: props.certificates.today,
    reason: '',
    reason_consent: false,
    change_reason: 'dates_corrected',
});
const clear = () => {
    form.cancel();
    form.reset();
    form.clearErrors();
};
const edit = (action: string, document: Document | null = null) => {
    clear();
    saved.value = false;
    form.action = action;
    form.document_id = document?.id ?? null;
    form.expected_branch_id = props.certificates.branchId;
    form.lock_version = props.certificates.lockVersion;
    form.idempotency_key = crypto.randomUUID();
    open.value = true;
};
watch(
    () => [
        form.action,
        form.document_id,
        form.language,
        form.start_date,
        form.end_date,
        form.reason,
        form.reason_consent,
        form.change_reason,
    ],
    () => {
        if (!form.processing) {
            form.idempotency_key = crypto.randomUUID();
        }
    },
);
watch(open, (value) => {
    if (!value) {
        clear();
    }
});
watch(
    [
        () => props.certificates.branchId,
        () => page.props.branchContext?.active?.id,
    ],
    () => {
        open.value = false;
        loaded.value = false;
        saved.value = false;
        clear();
    },
);
onBeforeUnmount(clear);
const submit = () => {
    if (form.processing || branchChanged.value) {
        return;
    }

    form.post(baseUrl.value, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            saved.value = true;
        },
    });
};
const days = computed(() => {
    const value =
        (Date.parse(form.end_date) - Date.parse(form.start_date)) / 86400000 +
        1;

    return Number.isInteger(value) && value > 0 ? value : 0;
});
</script>

<template>
    <Head title="Medical certificates" />
    <main
        class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-5 p-4 md:p-6"
    >
        <Link href="/queue" class="text-sm underline">Back to Queue</Link>
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Medical certificates</h1>
                <p
                    v-if="loaded && !branchChanged"
                    class="text-sm text-muted-foreground"
                >
                    {{ certificates.patientName }} ·
                    {{ certificates.visitNumber }} · MC only · no charge
                </p>
            </div>
            <Button
                v-if="certificates.canIssue && loaded"
                :disabled="
                    branchChanged ||
                    !certificates.credentialVerified ||
                    !certificates.canFirstIssue ||
                    certificates.documents.length > 0 ||
                    certificates.page > 1
                "
                @click="edit('issue')"
                >Issue MC</Button
            >
        </header>
        <p class="rounded-lg border bg-card p-4 text-sm">
            <strong>Draft wording — awaiting doctor approval.</strong> Fixed
            EN/BM revision 1. Issuance retains pre-signature content only.
            Wet-sign the paper and retain a signed duplicate. No signed original
            or final PDF is archived.
        </p>
        <p
            v-if="certificates.canIssue && !certificates.credentialVerified"
            role="alert"
            class="rounded-lg border border-amber-500 p-4"
        >
            Verified professional name and MMC registration are required. Ask
            the authorised credential manager; you cannot verify yourself.
        </p>
        <p
            v-if="saved"
            role="status"
            class="rounded-lg border border-emerald-500 p-4"
        >
            MC evidence saved. Review the document before printing.
        </p>
        <p v-if="!loaded || branchChanged" role="alert">
            Branch changed. Reload this visit before viewing MC history.
        </p>
        <section v-else class="space-y-3" aria-label="MC history">
            <p
                v-if="!certificates.documents.length"
                class="rounded-lg border bg-card p-6"
            >
                No MC has been issued for this visit. First issue requires
                Serving, not held, before clinical checkout.
            </p>
            <article
                v-for="document in certificates.documents"
                :key="document.id"
                class="flex flex-wrap items-center justify-between gap-4 rounded-lg border bg-card p-4"
            >
                <div class="min-w-0">
                    <h2 class="font-semibold break-words">
                        {{ document.number }}
                    </h2>
                    <p class="text-sm capitalize">{{ document.status }}</p>
                    <p class="text-xs text-muted-foreground">
                        Issued {{ document.issuedAt }} (UTC)
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a
                        v-if="certificates.canPrint"
                        :href="`${baseUrl}/${document.id}?print=1`"
                        target="_blank"
                        rel="noopener"
                        class="rounded-md border px-3 py-2 text-sm underline"
                        >View / print copy</a
                    >
                    <template v-if="document.canCorrect"
                        ><Button
                            variant="outline"
                            @click="edit('replace', document)"
                            >Replace</Button
                        ><Button
                            variant="outline"
                            @click="edit('void', document)"
                            >Void</Button
                        ></template
                    >
                </div>
            </article>
            <nav class="flex flex-wrap gap-3" aria-label="MC history pages">
                <Link
                    v-if="certificates.page > 1"
                    :href="`${baseUrl}?page=${certificates.page - 1}`"
                    class="underline"
                    >Previous</Link
                >
                <Link
                    v-if="certificates.hasMore"
                    :href="`${baseUrl}?page=${certificates.page + 1}`"
                    class="underline"
                    >Next</Link
                >
                <Button variant="outline" @click="router.reload()"
                    >Refresh history</Button
                >
            </nav>
        </section>
        <Dialog v-model:open="open"
            ><DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
                <DialogHeader
                    ><DialogTitle>{{
                        form.action === 'void'
                            ? 'Void MC'
                            : form.action === 'replace'
                              ? 'Replace MC'
                              : 'Issue MC'
                    }}</DialogTitle
                    ><DialogDescription
                        >Only the original issuer may correct or void. Old
                        content stays immutable. Wording remains
                        draft.</DialogDescription
                    ></DialogHeader
                >
                <form class="space-y-4" @submit.prevent="submit">
                    <template v-if="form.action !== 'void'">
                        <label class="block text-sm"
                            >Language<select
                                v-model="form.language"
                                class="mt-1 w-full rounded-md border bg-background p-2"
                            >
                                <option value="en">English · revision 1</option>
                                <option value="bm">
                                    Bahasa Melayu · revision 1
                                </option></select
                            ><InputError :message="form.errors.language"
                        /></label>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block text-sm"
                                >Start date<input
                                    v-model="form.start_date"
                                    type="date"
                                    required
                                    :max="certificates.today"
                                    class="mt-1 w-full min-w-0 rounded-md border bg-background p-2" /><InputError
                                    :message="form.errors.start_date"
                            /></label>
                            <label class="block text-sm"
                                >End date<input
                                    v-model="form.end_date"
                                    type="date"
                                    required
                                    :min="form.start_date"
                                    class="mt-1 w-full min-w-0 rounded-md border bg-background p-2" /><InputError
                                    :message="form.errors.end_date"
                            /></label>
                        </div>
                        <p class="text-sm">
                            {{ days }} inclusive day(s) · maximum 30.
                            Examination and issue dates are recorded by the
                            server.
                        </p>
                        <label class="block text-sm"
                            >Reason to print (optional, 200 characters)<textarea
                                v-model="form.reason"
                                maxlength="200"
                                rows="2"
                                class="mt-1 w-full rounded-md border bg-background p-2" /><InputError
                                :message="form.errors.reason"
                        /></label>
                        <p class="text-xs text-muted-foreground">
                            No diagnosis is copied automatically. Leave blank
                            unless the patient explicitly consents.
                        </p>
                        <label
                            v-if="form.reason"
                            class="flex items-start gap-2 text-sm"
                            ><input
                                v-model="form.reason_consent"
                                type="checkbox"
                                class="mt-1"
                            />The patient explicitly consents to this reason
                            appearing on the MC.</label
                        ><InputError :message="form.errors.reason_consent" />
                    </template>
                    <label v-if="form.action !== 'issue'" class="block text-sm"
                        >Change category<select
                            v-model="form.change_reason"
                            class="mt-1 w-full rounded-md border bg-background p-2"
                        >
                            <option value="dates_corrected">
                                Dates corrected
                            </option>
                            <option value="details_corrected">
                                Details corrected
                            </option>
                            <option value="entered_in_error">
                                Entered in error
                            </option>
                            <option value="patient_request">
                                Patient request
                            </option></select
                        ><InputError :message="form.errors.change_reason"
                    /></label>
                    <p v-if="form.action === 'void'" class="text-sm">
                        This permanently voids the selected MC. Its original
                        snapshot stays readable and every copy is marked VOID.
                    </p>
                    <InputError
                        v-for="key in [
                            'certificate',
                            'lock_version',
                            'expected_branch_id',
                            'idempotency_key',
                            'document_id',
                        ] as const"
                        :key="key"
                        :message="(form.errors as Record<string, string>)[key]"
                    />
                    <Button
                        v-if="form.errors.lock_version"
                        type="button"
                        variant="outline"
                        @click="
                            open = false;
                            router.reload();
                        "
                        >Reload history</Button
                    >
                    <DialogFooter
                        ><Button
                            type="button"
                            variant="outline"
                            :disabled="form.processing"
                            @click="open = false"
                            >Cancel</Button
                        ><Button
                            type="submit"
                            :disabled="form.processing || branchChanged"
                            >{{
                                form.processing
                                    ? 'Saving…'
                                    : form.action === 'void'
                                      ? 'Confirm void'
                                      : 'Save pre-signature MC'
                            }}</Button
                        ></DialogFooter
                    >
                </form>
            </DialogContent></Dialog
        >
    </main>
</template>
