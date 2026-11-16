<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Clipboard,
    Download,
    ExternalLink,
    Link2,
    QrCode,
    Printer,
    RotateCw,
    ShieldOff,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2FormCard from '@/components/v2/V2FormCard.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { usePageCrumbs } from '@/composables/usePageCrumbs';
import { formatDate, formatDateTime } from '@/lib/presentation';

type LinkRow = {
    publicId: string;
    branch: { code: string; name: string };
    label: string;
    status: 'active' | 'revoked' | 'expired';
    createdAt: string | null;
    revokedAt: string | null;
    expiresAt: string | null;
    activeQr: IssuedLink | null;
    requiresRotation: boolean;
};
type IssuedLink = {
    publicId: string;
    url: string;
    qrDataUri: string;
    expiresAt: string | null;
};
const props = defineProps<{
    links: LinkRow[];
    branches: Array<{ id: number; name: string }>;
}>();
const form = useForm({
    branch_id: '' as string | number,
    label: 'Public check-in',
});
const pending = ref<{ type: 'rotate' | 'revoke'; link: LinkRow } | null>(null);
const creating = ref(false);
const actionProcessing = ref(false);
const actionError = ref('');
const issuedLink = ref<IssuedLink | null>(null);
// V2OptionGroup works in strings; the form keeps the branch id as a number, as in Classic, so the request is the same.
const branchOptions = computed(() =>
    props.branches.map((branch) => ({
        value: String(branch.id),
        label: branch.name,
    })),
);
const branchChoice = computed({
    get: () => (form.branch_id === '' ? null : String(form.branch_id)),
    set: (value: string) => (form.branch_id = Number(value)),
});
usePageCrumbs(() => [
    { title: 'Clinic management', href: '/clinic-management' },
    { title: 'QR check-in links' },
]);
const outlineButton =
    'inline-flex h-11 items-center justify-center gap-2 rounded-[10px] border border-border bg-card px-4 text-sm font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:h-9';
const primaryButton =
    'inline-flex h-11 items-center justify-center gap-2 rounded-[10px] bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50 lg:h-9';
const box =
    'w-full min-h-11 rounded-[10px] border bg-background px-3 text-sm lg:min-h-9';
const statusTone = (status: LinkRow['status']) =>
    status === 'active'
        ? 'success'
        : status === 'expired'
          ? 'warning'
          : 'neutral';
const statusLabel = (status: LinkRow['status']) =>
    status === 'active'
        ? 'Active'
        : status === 'expired'
          ? 'Expired'
          : 'Revoked';
const copiedPublicId = ref<string | null>(null);
// V2 only: a failed rotate or revoke does not leave its message on the next confirmation.
watch(pending, () => (actionError.value = ''));

const csrfToken = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';
const request = async (
    url: string,
    method: 'POST' | 'DELETE',
    body?: object,
) => {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: body ? JSON.stringify(body) : undefined,
    });
    const data = response.status === 204 ? null : await response.json();

    if (!response.ok) {
        throw data;
    }

    return data as {
        issuedLink?: {
            publicId: string;
            url: string;
            qrDataUri: string;
            expiresAt: string | null;
        };
        errors?: Record<string, string[]>;
    } | null;
};
const create = async () => {
    if (creating.value) {
        return;
    }

    creating.value = true;
    form.clearErrors();

    try {
        const data = await request(
            '/public-checkin-links',
            'POST',
            form.data(),
        );
        issuedLink.value = data?.issuedLink ?? null;
        router.reload({
            only: ['links', 'branches'],
        });
    } catch (error) {
        const errors =
            (error as { errors?: Record<string, string[]> }).errors ?? {};

        for (const [field, messages] of Object.entries(errors)) {
            form.setError(
                field as 'branch_id' | 'label',
                messages[0] ?? 'Please check this field.',
            );
        }
    } finally {
        creating.value = false;
    }
};
const confirmAction = async () => {
    if (!pending.value || actionProcessing.value) {
        return;
    }

    const target = `/public-checkin-links/${pending.value.link.publicId}`;
    const type = pending.value.type;

    actionProcessing.value = true;
    actionError.value = '';

    try {
        const data = await request(
            type === 'rotate' ? `${target}/rotate` : target,
            type === 'rotate' ? 'POST' : 'DELETE',
        );
        issuedLink.value = data?.issuedLink ?? null;
        pending.value = null;
        router.reload({
            only: ['links', 'branches'],
        });
    } catch {
        actionError.value = 'The link could not be updated. Please try again.';
    } finally {
        actionProcessing.value = false;
    }
};
const copyLink = async (activeQr: IssuedLink) => {
    await navigator.clipboard.writeText(activeQr.url);
    copiedPublicId.value = activeQr.publicId;
};
const downloadQr = (link: LinkRow) => {
    if (!link.activeQr) {
        return;
    }

    const anchor = document.createElement('a');
    anchor.href = link.activeQr.qrDataUri;
    anchor.download = `kpone-check-in-${link.branch.code}.svg`;
    anchor.click();
};
const printQr = (link: LinkRow) => {
    if (!link.activeQr) {
        return;
    }

    const printWindow = window.open('', '_blank');

    if (!printWindow) {
        return;
    }

    printWindow.opener = null;
    printWindow.document.title = 'KPOne Check-in QR';
    const main = printWindow.document.createElement('main');
    main.style.cssText =
        'font-family:sans-serif;text-align:center;padding:2rem';
    const heading = printWindow.document.createElement('h1');
    heading.textContent = link.branch.name;
    const image = printWindow.document.createElement('img');
    image.alt = 'Branch check-in QR';
    image.src = link.activeQr.qrDataUri;
    image.style.cssText = 'width:22rem;max-width:90vw';
    image.addEventListener('load', () => printWindow.print(), { once: true });
    const label = printWindow.document.createElement('p');
    label.textContent = link.label;
    const expiry = printWindow.document.createElement('p');
    expiry.textContent = `Expires ${formatDateTime(link.expiresAt)}`;
    main.append(heading, image, label, expiry);
    printWindow.document.body.append(main);
};
</script>

<template>
    <Head title="QR check-in links" />
    <PageFrame>
        <V2WorkLayout
            title="QR check-in links"
            description="Issue and revoke branch-bound public landing links. No Patient information is staged until staff review and confirmation."
        >
            <template #main>
                <V2FormCard
                    v-if="issuedLink"
                    title="Active QR ready"
                    description="The link is encrypted at rest and remains available to authorised staff after refresh."
                >
                    <div class="grid gap-5 md:grid-cols-[12rem_minmax(0,1fr)]">
                        <img
                            :src="issuedLink.qrDataUri"
                            alt="Branch public patient intake QR code"
                            class="aspect-square w-48 max-w-full rounded-[14px] border bg-white p-2"
                        />
                        <div class="min-w-0 space-y-3">
                            <V2Field
                                v-slot="{ id }"
                                label="Public check-in URL"
                            >
                                <input
                                    :id="id"
                                    readonly
                                    :value="issuedLink.url"
                                    title="Tap to select the whole link"
                                    @focus="
                                        (
                                            $event.target as HTMLInputElement
                                        ).select()
                                    "
                                    :class="box"
                                />
                            </V2Field>
                            <div class="flex flex-wrap gap-2 max-sm:flex-col">
                                <button
                                    type="button"
                                    :class="outlineButton"
                                    @click="copyLink(issuedLink)"
                                >
                                    <Clipboard
                                        class="size-4"
                                        aria-hidden="true"
                                    />{{
                                        copiedPublicId === issuedLink.publicId
                                            ? 'Copied'
                                            : 'Copy link'
                                    }}
                                </button>
                                <a
                                    :href="issuedLink.url"
                                    target="_blank"
                                    rel="noreferrer"
                                    :class="outlineButton"
                                    ><ExternalLink
                                        class="size-4"
                                        aria-hidden="true"
                                    />Open</a
                                >
                            </div>
                            <p
                                class="flex items-center gap-2 text-xs text-muted-foreground"
                            >
                                <QrCode class="size-4" aria-hidden="true" />
                                Generated locally. No URL or token is sent to an
                                external QR service.
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Expires
                                {{ formatDateTime(issuedLink.expiresAt) }}.
                            </p>
                        </div>
                    </div>
                </V2FormCard>

                <V2FormCard
                    title="Create branch link"
                    description="Each branch can have one active link. Use Rotate to replace an existing link."
                >
                    <form class="grid gap-4" @submit.prevent="create">
                        <V2OptionGroup
                            v-model="branchChoice"
                            label="Branch"
                            :options="branchOptions"
                            :error="form.errors.branch_id"
                        />
                        <V2Field
                            v-slot="{ id, describedBy }"
                            label="Label"
                            :error="form.errors.label"
                        >
                            <input
                                :id="id"
                                v-model="form.label"
                                maxlength="120"
                                required
                                :aria-describedby="describedBy"
                                :class="box"
                            />
                        </V2Field>
                        <div>
                            <button
                                type="submit"
                                :class="primaryButton"
                                :disabled="creating"
                            >
                                <Link2 class="size-4" aria-hidden="true" />{{
                                    creating ? 'Creating…' : 'Create link'
                                }}
                            </button>
                        </div>
                    </form>
                </V2FormCard>

                <div class="grid gap-4 xl:grid-cols-2">
                    <section
                        v-for="link in links"
                        :key="link.publicId"
                        :aria-label="`${link.branch.name} check-in link`"
                        class="flex min-w-0 flex-col gap-4 rounded-[14px] border bg-card p-4 sm:p-5"
                    >
                        <header class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="text-base font-semibold">
                                    {{ link.branch.name }}
                                </h2>
                                <p class="text-sm text-muted-foreground">
                                    {{ link.label }} · {{ link.branch.code }}
                                </p>
                            </div>
                            <V2Chip
                                :tone="statusTone(link.status)"
                                :label="statusLabel(link.status)"
                            />
                        </header>
                        <dl
                            class="grid grid-cols-2 gap-3 text-sm text-muted-foreground sm:grid-cols-3"
                        >
                            <div>
                                <dt>Created</dt>
                                <dd class="mt-1 text-foreground">
                                    {{ formatDateTime(link.createdAt) }}
                                </dd>
                            </div>
                            <div>
                                <dt>Revoked</dt>
                                <dd class="mt-1 text-foreground">
                                    {{ formatDateTime(link.revokedAt) }}
                                </dd>
                            </div>
                            <div>
                                <dt>Expires</dt>
                                <dd class="mt-1 text-foreground">
                                    {{ formatDateTime(link.expiresAt) }}
                                </dd>
                            </div>
                        </dl>
                        <div
                            v-if="link.activeQr"
                            class="grid gap-4 rounded-[14px] border bg-muted/20 p-4 sm:grid-cols-[9rem_minmax(0,1fr)]"
                        >
                            <img
                                :src="link.activeQr.qrDataUri"
                                :alt="`Public intake QR for ${link.branch.name}`"
                                class="aspect-square w-36 max-w-full rounded-[10px] border bg-white p-2"
                            />
                            <div class="min-w-0 space-y-3">
                                <p class="text-sm font-medium">
                                    Current active QR
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    Viewing, copying, downloading, or printing
                                    does not rotate this link.
                                </p>
                                <div
                                    class="flex flex-wrap gap-2 max-sm:flex-col"
                                >
                                    <button
                                        type="button"
                                        :class="outlineButton"
                                        @click="copyLink(link.activeQr)"
                                    >
                                        <Clipboard
                                            class="size-4"
                                            aria-hidden="true"
                                        />{{
                                            copiedPublicId === link.publicId
                                                ? 'Copied'
                                                : 'Copy link'
                                        }}
                                    </button>
                                    <button
                                        type="button"
                                        :class="outlineButton"
                                        @click="downloadQr(link)"
                                    >
                                        <Download
                                            class="size-4"
                                            aria-hidden="true"
                                        />Download
                                    </button>
                                    <button
                                        type="button"
                                        :class="outlineButton"
                                        @click="printQr(link)"
                                    >
                                        <Printer
                                            class="size-4"
                                            aria-hidden="true"
                                        />Print
                                    </button>
                                </div>
                            </div>
                        </div>
                        <p
                            v-else-if="link.requiresRotation"
                            class="rounded-[10px] border border-warning/30 bg-warning/10 p-3 text-sm"
                        >
                            This older QR cannot be shown again because it was
                            created before secure storage was added. Rotate it
                            to make a new QR. The old QR stops working, so print
                            the new one.
                        </p>
                        <p v-else class="text-xs text-muted-foreground">
                            Historical link retained for audit. It cannot be
                            used.
                        </p>
                        <div
                            v-if="link.status === 'active'"
                            class="flex flex-wrap gap-2 max-sm:flex-col max-sm:gap-4"
                        >
                            <button
                                type="button"
                                :class="outlineButton"
                                @click="pending = { type: 'rotate', link }"
                            >
                                <RotateCw class="size-4" aria-hidden="true" />
                                Rotate
                            </button>
                            <button
                                type="button"
                                :class="[
                                    outlineButton,
                                    'border-destructive/40 text-destructive hover:bg-destructive/10',
                                ]"
                                @click="pending = { type: 'revoke', link }"
                            >
                                <ShieldOff class="size-4" aria-hidden="true" />
                                Revoke
                            </button>
                        </div>
                    </section>
                    <p
                        v-if="links.length === 0"
                        class="rounded-[14px] border bg-card p-6 text-center text-sm text-muted-foreground"
                    >
                        No public check-in links have been issued.
                    </p>
                </div>
            </template>
        </V2WorkLayout>
    </PageFrame>

    <V2Dialog
        :open="Boolean(pending)"
        :title="
            pending?.type === 'rotate'
                ? 'Rotate this check-in link?'
                : 'Revoke this check-in link?'
        "
        :description="
            pending?.type === 'rotate'
                ? 'The current public URL will stop working immediately. The replacement QR will remain securely available after refresh.'
                : 'The public URL will stop working immediately. This action does not delete its audit history.'
        "
        :confirm-label="
            pending?.type === 'rotate' ? 'Rotate link' : 'Revoke link'
        "
        :tone="pending?.type === 'revoke' ? 'danger' : 'default'"
        :busy="actionProcessing"
        @update:open="
            (open) => {
                if (!open) pending = null;
            }
        "
        @confirm="confirmAction"
    >
        <div v-if="pending" class="grid gap-3">
            <div class="grid gap-1 rounded-[10px] bg-muted/40 p-3 text-sm">
                <p class="font-medium">
                    {{ pending.link.branch.name }} · {{ pending.link.label }}
                </p>
                <p class="text-xs text-muted-foreground">
                    Created {{ formatDate(pending.link.createdAt) }}
                </p>
            </div>
            <p v-if="pending.type === 'rotate'" class="text-sm">
                Printed copies of the old QR stop working. Print the new one.
            </p>
            <p v-if="actionError" role="alert" class="text-sm text-destructive">
                {{ actionError }}
            </p>
        </div>
    </V2Dialog>
</template>
