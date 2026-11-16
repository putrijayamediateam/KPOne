<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import FileChooseButton from '@/components/catalogue/FileChooseButton.vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import { PageFrame } from '@/components/ui/page-frame';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { useSopsPage } from '@/composables/hr/useSopsPage';
import type { SopsPageProps } from '@/composables/hr/useSopsPage';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 SOPs (shown only in V2; Classic is `pages/HR/Sops.vue`, untouched). Same props, routes, permissions, lock
 * versions and idempotency keys: every request comes from `useSopsPage`, which Classic uses too, so this page sends none
 * of its own. A SOP opens only through the existing audited route (`/hr/sops/versions/{id}/open`); nothing is loaded
 * ahead. The one filled action is "Publish an SOP" (All SOPs); "I have read and understood" is outline, so a list of
 * SOPs never shows several filled buttons.
 */
const props = defineProps<SopsPageProps>();
usePageCrumbs(() => [{ title: 'SOPs' }]);

const {
    errors,
    failure,
    tabs,
    selectTab,
    openUrl,
    markOpened,
    isOpened,
    ack,
    acknowledge,
    form,
    startPublish,
    startVersion,
    onFile,
    sendForm,
    withdrawing,
    withdrawBusy,
    askWithdraw,
    withdraw,
    expanded,
    toggle,
    active,
    withdrawn,
} = useSopsPage(props);

const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:min-h-9';
const primaryButton =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50 lg:min-h-9';
const textLink =
    'inline-flex min-h-11 items-center text-sm font-medium text-primary underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9';
const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';

const description = computed(() =>
    props.sops.canManage
        ? 'Publish SOPs to the roles that must read them, and see who has not acknowledged yet. A new file is a new version, read again by everyone it is for.'
        : 'SOPs for your role. Open each one, then press I have read and understood.',
);
const fileStatus = computed(() =>
    form.file
        ? `${form.file.name} chosen.`
        : `No file chosen. PDF only, up to ${props.sops.options.maxMb} MB.`,
);
const publishMissing = computed(() => {
    if (form.title.trim() === '') {
        return 'Enter a title to continue.';
    }

    if (form.roles.length === 0) {
        return 'Choose at least one role to continue.';
    }

    if (!form.file) {
        return 'Choose the PDF to continue.';
    }

    return '';
});
</script>

<template>
    <Head title="SOPs" />
    <PageFrame>
        <V2WorkLayout title="SOPs" :description="description">
            <template v-if="sops.tab === 'manage' && sops.manage" #actions>
                <button
                    type="button"
                    :class="primaryButton"
                    data-test="sop-publish"
                    @click="startPublish"
                >
                    Publish an SOP
                </button>
            </template>
            <template #main>
                <div class="flex min-w-0 flex-col gap-4">
                    <SyntheticNote />
                    <V2Tabs
                        v-if="tabs.length > 1"
                        :model-value="sops.tab"
                        :tabs="tabs"
                        ariaLabel="SOP sections"
                        @update:model-value="selectTab"
                    />

                    <p
                        v-if="failure && !form.open && !withdrawing"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ failure }}
                    </p>

                    <!-- SOPs to read -->
                    <section
                        v-if="sops.tab === 'my' && sops.my"
                        aria-labelledby="v2-my-sops-title"
                        class="flex flex-col gap-3"
                        data-test="my-sops"
                    >
                        <h2 id="v2-my-sops-title" class="sr-only">
                            SOPs to read
                        </h2>
                        <div
                            v-if="!sops.my.length"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">
                                No SOPs for your role yet.
                            </p>
                            <p class="text-sm text-muted-foreground">
                                When HR publishes one for your role, it appears
                                here.
                            </p>
                        </div>
                        <article
                            v-for="sop in sops.my"
                            :key="sop.versionId"
                            class="flex flex-col gap-3 rounded-[14px] border bg-card p-4"
                            :aria-labelledby="`v2-sop-${sop.versionId}`"
                            data-test="sop-card"
                        >
                            <div
                                class="flex flex-wrap items-start justify-between gap-2"
                            >
                                <div class="min-w-0">
                                    <h3
                                        :id="`v2-sop-${sop.versionId}`"
                                        class="text-base font-semibold break-words"
                                    >
                                        {{ sop.title }}
                                    </h3>
                                    <p class="text-sm text-muted-foreground">
                                        Version {{ sop.version }} · published
                                        {{ sop.publishedOn }}
                                    </p>
                                </div>
                                <V2Chip
                                    v-if="sop.acknowledgedOn"
                                    tone="success"
                                    label="Acknowledged"
                                />
                            </div>
                            <p
                                v-if="!sop.acknowledgedOn && sop.earlier"
                                class="text-sm"
                                data-test="sop-new-version"
                            >
                                Version {{ sop.version }} is new. Please read it
                                again.
                                <span class="text-muted-foreground"
                                    >You acknowledged version
                                    {{ sop.earlier.version }} on
                                    {{ sop.earlier.on }}.</span
                                >
                            </p>
                            <p
                                v-if="sop.acknowledgedOn"
                                class="text-sm text-muted-foreground"
                            >
                                Acknowledged on {{ sop.acknowledgedOn }}.
                            </p>
                            <div class="flex flex-wrap items-center gap-2">
                                <a
                                    :href="openUrl(sop.versionId)"
                                    :class="button"
                                    :aria-label="`Open ${sop.title}, version ${sop.version} (PDF)`"
                                    data-test="sop-open"
                                    @click="markOpened(sop.versionId)"
                                    >Open</a
                                >
                                <template v-if="!sop.acknowledgedOn">
                                    <button
                                        type="button"
                                        :class="button"
                                        :disabled="
                                            !isOpened(sop) ||
                                            (ack.processing &&
                                                ack.versionId === sop.versionId)
                                        "
                                        :aria-describedby="
                                            isOpened(sop)
                                                ? undefined
                                                : `v2-hint-${sop.versionId}`
                                        "
                                        data-test="sop-acknowledge"
                                        @click="acknowledge(sop)"
                                    >
                                        {{
                                            ack.processing &&
                                            ack.versionId === sop.versionId
                                                ? 'Saving…'
                                                : 'I have read and understood'
                                        }}
                                    </button>
                                    <span
                                        v-if="!isOpened(sop)"
                                        :id="`v2-hint-${sop.versionId}`"
                                        class="text-sm text-muted-foreground"
                                        >Open the SOP first.</span
                                    >
                                </template>
                            </div>
                        </article>
                    </section>

                    <!-- All SOPs (Director, HR Manager) -->
                    <section
                        v-if="sops.tab === 'manage' && sops.manage"
                        aria-labelledby="v2-manage-sops-title"
                        class="flex flex-col gap-3"
                        data-test="manage-sops"
                    >
                        <h2
                            id="v2-manage-sops-title"
                            class="text-base font-semibold"
                        >
                            Published SOPs
                        </h2>
                        <div
                            v-if="!active.length"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">No SOPs published yet.</p>
                            <p class="text-sm text-muted-foreground">
                                Publish an SOP as a PDF and choose the roles
                                that must read it.
                            </p>
                        </div>
                        <article
                            v-for="sop in active"
                            :key="sop.id"
                            class="flex flex-col gap-3 rounded-[14px] border bg-card p-4"
                            :aria-labelledby="`v2-manage-${sop.id}`"
                            data-test="managed-sop"
                        >
                            <div
                                class="flex flex-wrap items-start justify-between gap-2"
                            >
                                <div class="min-w-0">
                                    <h3
                                        :id="`v2-manage-${sop.id}`"
                                        class="text-base font-semibold break-words"
                                    >
                                        {{ sop.title }}
                                    </h3>
                                    <p class="text-sm text-muted-foreground">
                                        Version {{ sop.version }} · published
                                        {{ sop.publishedOn }} · for
                                        {{ sop.roles.join(', ') }}
                                    </p>
                                </div>
                                <p
                                    class="text-sm font-medium tabular-nums"
                                    data-test="sop-count"
                                >
                                    {{ sop.acknowledged }} of
                                    {{ sop.audience }} acknowledged
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <a
                                    v-if="sop.versionId"
                                    :href="openUrl(sop.versionId)"
                                    :class="button"
                                    :aria-label="`Open ${sop.title}, version ${sop.version} (PDF)`"
                                    >Open</a
                                >
                                <button
                                    type="button"
                                    :class="button"
                                    :aria-expanded="expanded === sop.id"
                                    :aria-controls="`v2-details-${sop.id}`"
                                    @click="toggle(sop.id)"
                                >
                                    {{
                                        expanded === sop.id
                                            ? 'Hide details'
                                            : sop.notYet.length
                                              ? `Who hasn't acknowledged (${sop.notYet.length})`
                                              : 'Show versions'
                                    }}
                                </button>
                                <button
                                    type="button"
                                    :class="button"
                                    data-test="sop-new-version-button"
                                    @click="startVersion(sop)"
                                >
                                    New version
                                </button>
                                <button
                                    type="button"
                                    :class="`${button} text-destructive`"
                                    @click="askWithdraw(sop)"
                                >
                                    Withdraw
                                </button>
                            </div>
                            <div
                                v-if="expanded === sop.id"
                                :id="`v2-details-${sop.id}`"
                                class="grid gap-4 lg:grid-cols-2"
                            >
                                <section
                                    v-if="sop.notYet.length"
                                    :aria-labelledby="`v2-notyet-${sop.id}`"
                                >
                                    <h4
                                        :id="`v2-notyet-${sop.id}`"
                                        class="mb-2 text-sm font-semibold"
                                    >
                                        Who hasn't acknowledged version
                                        {{ sop.version }}
                                    </h4>
                                    <ul
                                        class="flex flex-col divide-y text-sm"
                                        data-test="sop-not-yet"
                                    >
                                        <li
                                            v-for="p in sop.notYet"
                                            :key="p.name + p.role"
                                            class="py-2 [overflow-wrap:anywhere]"
                                        >
                                            <span class="font-medium">{{
                                                p.name
                                            }}</span>
                                            · {{ p.role
                                            }}<template v-if="p.branch">
                                                · {{ p.branch }}</template
                                            >
                                            · published {{ p.publishedOn }}
                                        </li>
                                    </ul>
                                </section>
                                <section
                                    :aria-labelledby="`v2-versions-${sop.id}`"
                                >
                                    <h4
                                        :id="`v2-versions-${sop.id}`"
                                        class="mb-2 text-sm font-semibold"
                                    >
                                        Versions
                                    </h4>
                                    <ul class="flex flex-col divide-y text-sm">
                                        <li
                                            v-for="v in sop.versions"
                                            :key="v.versionId"
                                            class="flex flex-wrap items-center justify-between gap-2 py-2"
                                        >
                                            <span class="min-w-0"
                                                >Version {{ v.version }} ·
                                                {{ v.title }} ·
                                                {{ v.publishedOn }} by
                                                {{ v.publishedBy }} · for
                                                {{ v.roles.join(', ') }}</span
                                            >
                                            <a
                                                :href="openUrl(v.versionId)"
                                                :class="textLink"
                                                :aria-label="`Open version ${v.version} (PDF)`"
                                                >Open</a
                                            >
                                        </li>
                                    </ul>
                                </section>
                            </div>
                        </article>

                        <section
                            v-if="withdrawn.length"
                            aria-labelledby="v2-withdrawn-title"
                            class="flex flex-col gap-2"
                        >
                            <h2
                                id="v2-withdrawn-title"
                                class="text-sm font-semibold"
                            >
                                Withdrawn
                            </h2>
                            <ul
                                class="flex flex-col divide-y rounded-[14px] border bg-card text-sm"
                            >
                                <li
                                    v-for="sop in withdrawn"
                                    :key="sop.id"
                                    class="flex flex-wrap items-center justify-between gap-2 px-4 py-3"
                                >
                                    <span class="min-w-0"
                                        >{{ sop.title }} · version
                                        {{ sop.version }} · withdrawn
                                        {{ sop.withdrawnOn }} by
                                        {{ sop.withdrawnBy }}. Its
                                        acknowledgements are kept.</span
                                    >
                                    <a
                                        v-if="sop.versionId"
                                        :href="openUrl(sop.versionId)"
                                        :class="textLink"
                                        :aria-label="`Open ${sop.title}, version ${sop.version} (PDF)`"
                                        >Open</a
                                    >
                                </li>
                            </ul>
                        </section>
                    </section>
                </div>
            </template>
        </V2WorkLayout>

        <V2Dialog
            :open="form.open"
            :title="
                form.sop ? `New version of ${form.sop.title}` : 'Publish an SOP'
            "
            :description="
                form.sop
                    ? `Version ${form.sop.version + 1}. Earlier versions and acknowledgements stay. Everyone it is for reads it again.`
                    : 'Everyone in the roles you choose sees it under SOPs to read.'
            "
            :confirm-label="
                form.processing
                    ? 'Publishing…'
                    : form.sop
                      ? 'Publish new version'
                      : 'Publish'
            "
            :busy="form.processing"
            :confirm-disabled="publishMissing !== ''"
            :confirm-disabled-reason="publishMissing"
            @update:open="form.open = $event"
            @confirm="sendForm"
        >
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
            <V2Field
                v-slot="{ id, describedBy }"
                label="Title"
                required
                :error="errors.title"
            >
                <input
                    :id="id"
                    v-model="form.title"
                    maxlength="120"
                    :class="inputClass"
                    :aria-invalid="!!errors.title"
                    :aria-describedby="describedBy"
                    data-test="sop-title"
                />
            </V2Field>
            <fieldset class="flex min-w-0 flex-col gap-1">
                <legend class="mb-1 text-sm font-medium">
                    Roles that must read it *
                </legend>
                <label
                    v-for="r in sops.options.roles"
                    :key="r.value"
                    class="flex min-h-11 items-center gap-2 text-sm lg:min-h-9"
                >
                    <input
                        v-model="form.roles"
                        type="checkbox"
                        :value="r.value"
                        class="size-4"
                    />
                    {{ r.label }}
                </label>
                <p
                    v-if="errors.roles"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ errors.roles }}
                </p>
            </fieldset>
            <FileChooseButton
                accept="application/pdf"
                :label="form.file ? 'Choose another PDF' : 'Choose the PDF'"
                :status="fileStatus"
                status-id="v2-sop-file-status"
                @change="onFile"
            />
            <p v-if="errors.file" role="alert" class="text-sm text-destructive">
                {{ errors.file }}
            </p>
        </V2Dialog>

        <V2Dialog
            :open="withdrawing !== null"
            title="Withdraw this SOP?"
            :description="
                withdrawing
                    ? `${withdrawing.title}. It leaves everyone's SOPs to read. Its versions and acknowledgements are kept. It cannot come back; publish a new SOP instead.`
                    : ''
            "
            :confirm-label="withdrawBusy ? 'Withdrawing…' : 'Withdraw'"
            tone="danger"
            :busy="withdrawBusy"
            @update:open="(v: boolean) => !v && (withdrawing = null)"
            @confirm="withdraw"
        >
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
        </V2Dialog>
    </PageFrame>
</template>
