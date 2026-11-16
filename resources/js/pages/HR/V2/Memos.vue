<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import FileChooseButton from '@/components/catalogue/FileChooseButton.vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { useMemosPage } from '@/composables/hr/useMemosPage';
import type { MemosPageProps } from '@/composables/hr/useMemosPage';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 Memos (shown only in V2; Classic is `pages/HR/Memos.vue`, untouched). Same props, routes, permissions, lock
 * versions and idempotency keys: every request comes from `useMemosPage`, which Classic uses too, so this page sends none
 * of its own. A memo's text is fetched only when a person presses Open (the server records the open first); nothing is
 * prefetched. The one filled action is "Write a memo" (All memos); "I have read this" is outline. Nothing is sent by
 * email or message: people see a memo here.
 */
const props = defineProps<MemosPageProps>();
usePageCrumbs(() => [{ title: 'Memos' }]);

const {
    errors,
    failure,
    statusLabel,
    tabs,
    selectTab,
    bodies,
    opening,
    openError,
    pdfUrl,
    openMemo,
    hideBody,
    markPdfOpened,
    ready,
    hint,
    ack,
    acknowledge,
    form,
    fileInput,
    fileStatus,
    startPublish,
    onFile,
    replaceOptions,
    audienceWords,
    peopleWords,
    sendForm,
    withdrawing,
    withdrawReason,
    withdrawBusy,
    askWithdraw,
    withdraw,
    expanded,
    toggle,
    published,
    withdrawn,
} = useMemosPage(props);

const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:min-h-9';
const primaryButton =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50 lg:min-h-9';
const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
const textareaClass =
    'w-full rounded-[10px] border border-input bg-card px-3 py-2 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px]';

// The manager sentence belongs to the manager tab only (Daniel).
const description = computed(() =>
    props.memos.tab === 'manage'
        ? 'Write a memo for all staff, a branch or a role, and see who has not read it yet. A correction is a new memo that replaces the old one. Nothing is sent by email or message: people see it here.'
        : 'Memos from HR. Open each one, then press I have read this.',
);
const publishMissing = computed(() => {
    if (form.title.trim() === '') {
        return 'Enter a title to continue.';
    }

    if (form.body.trim() === '') {
        return 'Write the memo to continue.';
    }

    return '';
});
const statusTone = (status: string) =>
    status === 'withdrawn' ? 'danger' : 'neutral';
const withdrawMissing = computed(() =>
    withdrawReason.value === '' ? 'Pick a reason to continue.' : '',
);
</script>

<template>
    <Head title="Memos" />
    <PageFrame>
        <V2WorkLayout title="Memos" :description="description">
            <template v-if="memos.tab === 'manage' && memos.manage" #actions>
                <button
                    type="button"
                    :class="primaryButton"
                    data-test="memo-publish"
                    @click="startPublish()"
                >
                    Write a memo
                </button>
            </template>
            <template #main>
                <div class="flex min-w-0 flex-col gap-4">
                    <SyntheticNote />
                    <V2Tabs
                        v-if="tabs.length > 1"
                        :model-value="memos.tab"
                        :tabs="tabs"
                        ariaLabel="Memo sections"
                        @update:model-value="selectTab"
                    />

                    <p
                        v-if="failure && !form.open && !withdrawing"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ failure }}
                    </p>

                    <!-- My memos -->
                    <section
                        v-if="memos.tab === 'my' && memos.my"
                        aria-labelledby="v2-my-memos-title"
                        class="flex flex-col gap-3"
                        data-test="my-memos"
                    >
                        <h2 id="v2-my-memos-title" class="sr-only">My memos</h2>
                        <div
                            v-if="!memos.my.length"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">No memos for you.</p>
                            <p class="text-sm text-muted-foreground">
                                When HR publishes a memo for you, it appears
                                here.
                            </p>
                        </div>
                        <article
                            v-for="memo in memos.my"
                            :key="memo.id"
                            class="flex flex-col gap-3 rounded-[14px] border bg-card p-4"
                            :aria-labelledby="`v2-memo-${memo.id}`"
                            data-test="memo-card"
                        >
                            <div
                                class="flex flex-wrap items-start justify-between gap-2"
                            >
                                <div class="min-w-0">
                                    <h3
                                        :id="`v2-memo-${memo.id}`"
                                        class="text-base font-semibold break-words"
                                    >
                                        {{ memo.title }}
                                    </h3>
                                    <p class="text-sm text-muted-foreground">
                                        {{ memo.publishedBy }} ·
                                        {{ memo.publishedOn
                                        }}<template v-if="memo.openUntil">
                                            · open until
                                            {{ memo.openUntil }}</template
                                        >
                                    </p>
                                </div>
                                <V2Chip
                                    v-if="memo.acknowledgedOn"
                                    tone="success"
                                    label="Read"
                                />
                                <V2Chip
                                    v-else-if="memo.status !== 'open'"
                                    :tone="statusTone(memo.status)"
                                    :label="statusLabel[memo.status]"
                                />
                            </div>

                            <p
                                v-if="memo.replacementWithdrawnOn"
                                class="text-sm"
                                data-test="memo-applies-again"
                            >
                                Its replacement was withdrawn on
                                {{ memo.replacementWithdrawnOn }}; this memo
                                applies again.
                            </p>
                            <p
                                v-if="
                                    memo.status === 'replaced' &&
                                    !memo.acknowledgedOn
                                "
                                class="text-sm"
                                data-test="memo-replaced"
                            >
                                <template v-if="memo.replacedBy"
                                    >This memo was replaced by a newer memo.
                                    Please read the newer one.</template
                                ><template v-else
                                    >This memo was replaced and no longer takes
                                    reads.</template
                                >
                            </p>
                            <p
                                v-else-if="
                                    memo.status === 'closed' &&
                                    !memo.acknowledgedOn
                                "
                                class="text-sm text-muted-foreground"
                                data-test="memo-closed"
                            >
                                This memo is closed. It no longer takes new
                                reads.
                            </p>
                            <p
                                v-if="memo.acknowledgedOn"
                                class="text-sm text-muted-foreground"
                            >
                                You read this on {{ memo.acknowledgedOn }}.
                            </p>

                            <div
                                v-if="bodies[memo.id] !== undefined"
                                class="rounded-[10px] border bg-muted/40 p-3 text-sm break-words whitespace-pre-wrap"
                                data-test="memo-body"
                            >
                                {{ bodies[memo.id] }}
                            </div>
                            <p
                                v-if="openError[memo.id]"
                                class="text-sm text-destructive"
                                role="alert"
                            >
                                {{ openError[memo.id] }}
                            </p>

                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    :class="button"
                                    :disabled="opening === memo.id"
                                    :aria-label="`${bodies[memo.id] === undefined ? 'Open' : 'Reload'} ${memo.title}`"
                                    data-test="memo-open"
                                    @click="openMemo(memo, true)"
                                >
                                    {{
                                        opening === memo.id
                                            ? 'Opening…'
                                            : bodies[memo.id] === undefined
                                              ? 'Open'
                                              : 'Open again'
                                    }}
                                </button>
                                <button
                                    v-if="bodies[memo.id] !== undefined"
                                    type="button"
                                    :class="button"
                                    @click="hideBody(memo.id)"
                                >
                                    Hide
                                </button>
                                <a
                                    v-if="memo.hasPdf"
                                    :href="pdfUrl(memo.id)"
                                    :class="button"
                                    :aria-label="`Open the PDF of ${memo.title}`"
                                    data-test="memo-pdf"
                                    @click="markPdfOpened(memo.id)"
                                    >Open the PDF</a
                                >
                                <template
                                    v-if="
                                        memo.status === 'open' &&
                                        !memo.acknowledgedOn
                                    "
                                >
                                    <button
                                        type="button"
                                        :class="button"
                                        :disabled="
                                            !ready(memo) ||
                                            (ack.processing &&
                                                ack.id === memo.id)
                                        "
                                        :aria-describedby="
                                            ready(memo)
                                                ? undefined
                                                : `v2-hint-${memo.id}`
                                        "
                                        data-test="memo-acknowledge"
                                        @click="acknowledge(memo)"
                                    >
                                        {{
                                            ack.processing && ack.id === memo.id
                                                ? 'Saving…'
                                                : 'I have read this'
                                        }}
                                    </button>
                                    <span
                                        v-if="!ready(memo)"
                                        :id="`v2-hint-${memo.id}`"
                                        class="text-sm text-muted-foreground"
                                        >{{ hint(memo) }}</span
                                    >
                                </template>
                            </div>
                        </article>
                    </section>

                    <!-- All memos (Director, HR Manager) -->
                    <section
                        v-if="memos.tab === 'manage' && memos.manage"
                        aria-labelledby="v2-manage-memos-title"
                        class="flex flex-col gap-3"
                        data-test="manage-memos"
                    >
                        <h2
                            id="v2-manage-memos-title"
                            class="text-base font-semibold"
                        >
                            Published memos
                        </h2>
                        <div
                            v-if="!published.length"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">No memos yet.</p>
                            <p class="text-sm text-muted-foreground">
                                Write a memo and choose who it is for.
                            </p>
                        </div>
                        <article
                            v-for="memo in published"
                            :key="memo.id"
                            class="flex flex-col gap-3 rounded-[14px] border bg-card p-4"
                            :aria-labelledby="`v2-manage-${memo.id}`"
                            data-test="managed-memo"
                        >
                            <div
                                class="flex flex-wrap items-start justify-between gap-2"
                            >
                                <div class="min-w-0">
                                    <h3
                                        :id="`v2-manage-${memo.id}`"
                                        class="text-base font-semibold break-words"
                                    >
                                        {{ memo.title }}
                                    </h3>
                                    <p class="text-sm text-muted-foreground">
                                        {{ memo.publishedOn }} by
                                        {{ memo.publishedBy }} · for
                                        {{ memo.audienceLabel
                                        }}<template v-if="memo.openUntil">
                                            · open until
                                            {{ memo.openUntil }}</template
                                        ><template v-if="memo.hasPdf">
                                            · with PDF</template
                                        ><template v-if="memo.replacesTitle">
                                            · replaces "{{
                                                memo.replacesTitle
                                            }}"</template
                                        >
                                    </p>
                                </div>
                                <div class="flex flex-col items-end gap-1">
                                    <V2Chip
                                        v-if="memo.status !== 'open'"
                                        :tone="statusTone(memo.status)"
                                        :label="statusLabel[memo.status]"
                                    />
                                    <p
                                        class="text-sm font-medium tabular-nums"
                                        data-test="memo-count"
                                    >
                                        {{ memo.read }} of
                                        {{ memo.expected }} expected now have
                                        read
                                    </p>
                                </div>
                            </div>
                            <p
                                v-if="memo.replacementWithdrawnOn"
                                class="text-sm"
                                data-test="memo-applies-again"
                            >
                                Its replacement was withdrawn on
                                {{ memo.replacementWithdrawnOn }}; this memo
                                applies again.
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    :class="button"
                                    :disabled="opening === memo.id"
                                    @click="openMemo(memo, false)"
                                >
                                    Open
                                </button>
                                <a
                                    v-if="memo.hasPdf"
                                    :href="pdfUrl(memo.id)"
                                    :class="button"
                                    :aria-label="`Open the PDF of ${memo.title}`"
                                    >Open the PDF</a
                                >
                                <button
                                    type="button"
                                    :class="button"
                                    :aria-expanded="expanded === memo.id"
                                    :aria-controls="`v2-details-${memo.id}`"
                                    @click="toggle(memo.id)"
                                >
                                    {{
                                        expanded === memo.id
                                            ? 'Hide who has not read'
                                            : `Who hasn't read yet (${memo.notYet.length})`
                                    }}
                                </button>
                                <button
                                    v-if="
                                        memo.status === 'open' ||
                                        memo.status === 'closed'
                                    "
                                    type="button"
                                    :class="button"
                                    data-test="memo-replace"
                                    @click="startPublish(memo.id)"
                                >
                                    Replace with a new memo
                                </button>
                                <button
                                    type="button"
                                    :class="`${button} text-destructive`"
                                    @click="askWithdraw(memo)"
                                >
                                    Withdraw
                                </button>
                            </div>
                            <p
                                v-if="openError[memo.id]"
                                class="text-sm text-destructive"
                                role="alert"
                            >
                                {{ openError[memo.id] }}
                            </p>
                            <div
                                v-if="bodies[memo.id] !== undefined"
                                class="flex flex-col items-start gap-2"
                            >
                                <div
                                    class="w-full rounded-[10px] border bg-muted/40 p-3 text-sm break-words whitespace-pre-wrap"
                                    data-test="memo-body"
                                >
                                    {{ bodies[memo.id] }}
                                </div>
                                <button
                                    type="button"
                                    :class="button"
                                    @click="hideBody(memo.id)"
                                >
                                    Hide
                                </button>
                            </div>
                            <div
                                v-if="expanded === memo.id"
                                :id="`v2-details-${memo.id}`"
                                class="flex flex-col gap-2"
                            >
                                <p
                                    v-if="memo.status === 'replaced'"
                                    class="text-sm"
                                    data-test="memo-replaced-note"
                                >
                                    Replaced by "{{ memo.replacedByTitle }}".
                                    The people below are asked to read the newer
                                    memo.
                                </p>
                                <h4 class="text-sm font-semibold">
                                    Who hasn't read<template
                                        v-if="memo.status === 'replaced'"
                                    >
                                        (replaced memo)</template
                                    >
                                </h4>
                                <p
                                    v-if="!memo.notYet.length"
                                    class="text-sm text-muted-foreground"
                                >
                                    Everyone expected now has read it.
                                </p>
                                <ul
                                    v-else
                                    class="flex flex-col divide-y text-sm"
                                    data-test="memo-not-yet"
                                >
                                    <li
                                        v-for="p in memo.notYet"
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
                                    v-for="memo in withdrawn"
                                    :key="memo.id"
                                    class="px-4 py-3 [overflow-wrap:anywhere]"
                                >
                                    {{ memo.title }} · withdrawn
                                    {{ memo.withdrawnOn }} by
                                    {{ memo.withdrawnBy
                                    }}<template v-if="memo.withdrawnReason">
                                        ({{ memo.withdrawnReason }})</template
                                    >. {{ memo.read }} of
                                    {{ memo.expected }} had read it; every read
                                    is kept.
                                </li>
                            </ul>
                        </section>
                    </section>
                </div>
            </template>
        </V2WorkLayout>

        <V2Dialog
            :open="form.open"
            title="Write a memo"
            size="tall"
            :confirm-label="form.processing ? 'Publishing…' : 'Publish memo'"
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
                hint="Don't put a staff member's name, health or pay in the title."
                :error="errors.title"
            >
                <input
                    :id="id"
                    v-model="form.title"
                    maxlength="120"
                    :class="inputClass"
                    :aria-invalid="!!errors.title"
                    :aria-describedby="describedBy"
                    data-test="memo-title"
                />
            </V2Field>
            <V2Field
                v-slot="{ id, describedBy }"
                label="Memo"
                required
                :hint="`Plain text, ${form.body.length} of ${memos.options.maxBody} characters.`"
                :error="errors.body"
            >
                <textarea
                    :id="id"
                    v-model="form.body"
                    rows="8"
                    :maxlength="memos.options.maxBody"
                    :class="textareaClass"
                    :aria-invalid="errors.body ? 'true' : undefined"
                    :aria-describedby="describedBy"
                    data-test="memo-text"
                ></textarea>
            </V2Field>
            <fieldset class="flex min-w-0 flex-col gap-1">
                <legend class="mb-1 text-sm font-medium">
                    Branches (none ticked means all)
                </legend>
                <label
                    v-for="b in memos.options.branches"
                    :key="b.value"
                    class="flex min-h-11 items-center gap-2 text-sm lg:min-h-9"
                >
                    <input
                        v-model="form.branches"
                        type="checkbox"
                        :value="b.value"
                        class="size-4"
                    />
                    {{ b.label }}
                </label>
            </fieldset>
            <fieldset class="flex min-w-0 flex-col gap-1">
                <legend class="mb-1 text-sm font-medium">
                    Roles (none ticked means all)
                </legend>
                <label
                    v-for="r in memos.options.roles"
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
                <p class="text-xs text-muted-foreground">
                    With both branches and roles ticked, a person must match a
                    ticked branch and a ticked role.
                </p>
            </fieldset>
            <p
                class="text-sm font-medium"
                role="status"
                data-test="memo-audience-count"
            >
                This memo goes to: {{ audienceWords }} (about {{ peopleWords }})
            </p>
            <p
                v-if="errors.audience"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ errors.audience }}
            </p>
            <V2DateField
                v-model="form.openUntil"
                label="Open until (optional)"
                :min="memos.options.today"
                :error="errors.open_until"
            />
            <V2Field
                v-slot="{ id }"
                label="Replaces (optional)"
                :error="errors.replaces"
            >
                <OperationalSelect
                    :id="id"
                    :model-value="form.replaces"
                    :options="replaceOptions"
                    label="Replaces"
                    @update:model-value="(v) => (form.replaces = String(v))"
                />
            </V2Field>
            <FileChooseButton
                ref="fileInput"
                accept="application/pdf"
                :label="
                    form.file ? 'Choose another PDF' : 'Choose PDF (optional)'
                "
                :status="fileStatus"
                status-id="v2-memo-file-status"
                aria-describedby="v2-memo-file-help"
                @change="onFile"
            />
            <p id="v2-memo-file-help" class="text-xs text-muted-foreground">
                PDF only, up to {{ memos.options.maxMb }} MB. People open it
                before they can mark the memo as read.
            </p>
            <p v-if="errors.file" role="alert" class="text-sm text-destructive">
                {{ errors.file }}
            </p>
            <template #footer-note>
                <p class="text-sm" data-test="memo-publish-note">
                    {{ form.title ? `${form.title}. ` : '' }}It goes to
                    {{ audienceWords }} (about {{ peopleWords }}). A published
                    memo cannot be edited; to correct it you publish a new memo
                    that replaces it.
                </p>
            </template>
        </V2Dialog>

        <V2Dialog
            :open="withdrawing !== null"
            title="Withdraw this memo?"
            :description="
                withdrawing
                    ? `${withdrawing.title}. Staff will no longer see it in their memos, and it takes no new reads. Every read already given is kept. It cannot come back; publish a new memo instead.`
                    : ''
            "
            :confirm-label="withdrawBusy ? 'Withdrawing…' : 'Withdraw'"
            tone="danger"
            :busy="withdrawBusy"
            :confirm-disabled="withdrawMissing !== ''"
            :confirm-disabled-reason="withdrawMissing"
            @update:open="(v: boolean) => !v && (withdrawing = null)"
            @confirm="withdraw"
        >
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
            <V2OptionGroup
                :model-value="withdrawReason || null"
                :options="memos.options.withdrawReasons"
                label="Why"
                required
                :error="errors.reason"
                @update:model-value="withdrawReason = $event ?? ''"
            />
        </V2Dialog>
    </PageFrame>
</template>
