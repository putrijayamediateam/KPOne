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
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import OperationalSelect from '@/components/ui/select/OperationalSelect.vue';
import { formatDateTime } from '@/lib/presentation';

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
const branchOptions = computed(() =>
    props.branches.map((branch) => ({ value: branch.id, label: branch.name })),
);
const copiedPublicId = ref<string | null>(null);

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
    <Head title="Public Check-In Links" />
    <main class="flex flex-1 flex-col gap-6 p-4 md:p-7">
        <header class="space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight">
                Public Check-In Links
            </h1>
            <p class="text-sm text-muted-foreground">
                Issue and revoke branch-bound public landing links. No Patient
                information is staged until staff review and confirmation.
            </p>
        </header>

        <Card
            v-if="issuedLink"
            class="border-pink-200 bg-pink-50/50 dark:border-pink-900 dark:bg-pink-950/20"
        >
            <CardHeader
                ><CardTitle class="text-base">Active QR ready</CardTitle
                ><CardDescription
                    >The link is encrypted at rest and remains available to
                    authorised staff after refresh.</CardDescription
                ></CardHeader
            >
            <CardContent class="grid gap-5 md:grid-cols-[12rem_1fr]">
                <img
                    :src="issuedLink.qrDataUri"
                    alt="Branch public patient intake QR code"
                    class="aspect-square w-48 rounded-xl border bg-white p-2"
                />
                <div class="space-y-3">
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <Input
                            readonly
                            :model-value="issuedLink.url"
                            aria-label="New public check-in URL"
                        /><Button
                            type="button"
                            variant="outline"
                            class="cursor-pointer"
                            @click="copyLink(issuedLink)"
                            ><Clipboard class="size-4" />{{
                                copiedPublicId === issuedLink.publicId
                                    ? 'Copied'
                                    : 'Copy link'
                            }}</Button
                        ><Button as-child
                            ><a
                                :href="issuedLink.url"
                                target="_blank"
                                rel="noreferrer"
                                ><ExternalLink class="size-4" />Open</a
                            ></Button
                        >
                    </div>
                    <p
                        class="flex items-center gap-2 text-xs text-muted-foreground"
                    >
                        <QrCode class="size-4" />Generated locally. No URL or
                        token is sent to an external QR service.
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Expires {{ formatDateTime(issuedLink.expiresAt) }}.
                    </p>
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader
                ><CardTitle class="text-base">Create branch link</CardTitle
                ><CardDescription
                    >Each branch can have one active link. Use Rotate to replace
                    an existing link.</CardDescription
                ></CardHeader
            >
            <CardContent
                ><form
                    class="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end"
                    @submit.prevent="create"
                >
                    <div class="space-y-2">
                        <Label id="checkin-branch-label" for="checkin-branch"
                            >Branch</Label
                        ><OperationalSelect
                            id="checkin-branch"
                            v-model="form.branch_id"
                            :options="branchOptions"
                            label="Branch"
                            labelledby="checkin-branch-label"
                            placeholder="Select branch"
                            :invalid="Boolean(form.errors.branch_id)"
                        />
                        <p
                            v-if="form.errors.branch_id"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.branch_id }}
                        </p>
                    </div>
                    <div class="space-y-2">
                        <Label for="checkin-label">Label</Label
                        ><Input
                            id="checkin-label"
                            v-model="form.label"
                            maxlength="120"
                            required
                        />
                        <p
                            v-if="form.errors.label"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.label }}
                        </p>
                    </div>
                    <Button :disabled="creating" class="cursor-pointer"
                        ><Link2 class="size-4" />Create link</Button
                    >
                </form></CardContent
            >
        </Card>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card v-for="link in links" :key="link.publicId">
                <CardHeader
                    ><div class="flex items-start justify-between gap-3">
                        <div>
                            <CardTitle>{{ link.branch.name }}</CardTitle
                            ><CardDescription
                                >{{ link.label }} ·
                                {{ link.branch.code }}</CardDescription
                            >
                        </div>
                        <Badge
                            :variant="
                                link.status === 'active'
                                    ? 'secondary'
                                    : 'outline'
                            "
                            >{{
                                link.status === 'active'
                                    ? 'Active'
                                    : link.status === 'expired'
                                      ? 'Expired'
                                      : 'Revoked'
                            }}</Badge
                        >
                    </div></CardHeader
                >
                <CardContent class="space-y-4 text-sm"
                    ><dl class="grid grid-cols-2 gap-3 text-muted-foreground">
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
                        class="grid gap-4 rounded-xl border bg-muted/20 p-4 sm:grid-cols-[9rem_1fr]"
                    >
                        <img
                            :src="link.activeQr.qrDataUri"
                            :alt="`Public intake QR for ${link.branch.name}`"
                            class="aspect-square w-36 rounded-lg border bg-white p-2"
                        />
                        <div class="min-w-0 space-y-3">
                            <p class="text-sm font-medium">Current active QR</p>
                            <p class="text-xs text-muted-foreground">
                                Viewing, copying, downloading, or printing does
                                not rotate this link.
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    @click="copyLink(link.activeQr)"
                                    ><Clipboard class="size-4" />{{
                                        copiedPublicId === link.publicId
                                            ? 'Copied'
                                            : 'Copy link'
                                    }}</Button
                                ><Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    @click="downloadQr(link)"
                                    ><Download class="size-4" />Download</Button
                                ><Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    @click="printQr(link)"
                                    ><Printer class="size-4" />Print</Button
                                >
                            </div>
                        </div>
                    </div>
                    <p
                        v-else-if="link.requiresRotation"
                        class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
                    >
                        QR lama ini tidak boleh dipaparkan semula kerana ia
                        dicipta sebelum fungsi simpanan selamat diperkenalkan.
                        Sila rotate sekali untuk menghasilkan QR baharu.
                    </p>
                    <p v-else class="text-xs text-muted-foreground">
                        Historical link retained for audit. It cannot be used.
                    </p>
                    <div
                        v-if="link.status === 'active'"
                        class="flex flex-wrap gap-2"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            class="cursor-pointer"
                            @click="pending = { type: 'rotate', link }"
                            ><RotateCw class="size-4" />Rotate</Button
                        ><Button
                            type="button"
                            variant="destructive"
                            class="cursor-pointer"
                            @click="pending = { type: 'revoke', link }"
                            ><ShieldOff class="size-4" />Revoke</Button
                        >
                    </div></CardContent
                >
            </Card>
            <Card v-if="links.length === 0"
                ><CardContent
                    class="py-10 text-center text-sm text-muted-foreground"
                    >No public check-in links have been issued.</CardContent
                ></Card
            >
        </div>

        <OperationalConfirmDialog
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
            :processing-label="
                pending?.type === 'rotate' ? 'Rotating…' : 'Revoking…'
            "
            :processing="actionProcessing"
            :error="actionError"
            :destructive="pending?.type === 'revoke'"
            @update:open="
                (open) => {
                    if (!open) pending = null;
                }
            "
            @confirm="confirmAction"
        />
    </main>
</template>
