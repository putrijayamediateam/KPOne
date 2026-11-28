<script setup lang="ts">
// HR-8 /hr/memos: memos to read (everyone) and the memo list with who has not read yet (Director, HR Manager).
// In KPOne only: nothing is sent by email, push, SMS or WhatsApp.
import { Head } from '@inertiajs/vue3';
import FileChooseButton from '@/components/catalogue/FileChooseButton.vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { DateField } from '@/components/ui/date-field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { PageHeader } from '@/components/ui/page-header';
import { OperationalSelect } from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { EmptyState, ErrorState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTabs } from '@/components/ui/tabs';
import { useMemosPage } from '@/composables/hr/useMemosPage';
import type { MemosPageProps } from '@/composables/hr/useMemosPage';

const props = defineProps<MemosPageProps>();

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
    confirmPublish,
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
</script>

<template>
    <Head title="Memos" />
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-4 px-4 py-4 md:px-5">
        <div class="flex flex-col gap-1">
            <PageHeader
                eyebrow="KPOne HR"
                title="Memos"
                :description="
                    memos.canPublish
                        ? 'Write a memo for all staff, a branch or a role, and see who has not read it yet. A correction is a new memo that replaces the old one. Nothing is sent by email or message: people see it here.'
                        : 'Memos from HR. Open each one, then press I have read this.'
                "
            />
            <SyntheticNote />
        </div>

        <OperationalTabs
            v-if="tabs.length > 1"
            :model-value="memos.tab"
            :tabs="tabs"
            label="Memo sections"
            class="min-w-0 max-lg:[&>button]:h-11 max-sm:[&>button]:px-2.5"
            @update:model-value="selectTab"
        />

        <ErrorState
            v-if="failure && !form.open && !withdrawing"
            :message="failure"
        />

        <!-- My memos -->
        <section
            v-if="memos.tab === 'my' && memos.my"
            aria-labelledby="my-memos-title"
            class="flex flex-col gap-3"
            data-test="my-memos"
        >
            <h2 id="my-memos-title" class="sr-only">My memos</h2>
            <EmptyState
                v-if="!memos.my.length"
                title="No memos for you."
                description="When HR publishes a memo for you, it appears here."
            />
            <article
                v-for="memo in memos.my"
                :key="memo.id"
                class="flex flex-col gap-3 rounded-xl border bg-card p-4"
                :aria-labelledby="`memo-${memo.id}`"
                data-test="memo-card"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h3
                            :id="`memo-${memo.id}`"
                            class="text-base font-semibold break-words"
                        >
                            {{ memo.title }}
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            {{ memo.publishedBy }} · {{ memo.publishedOn
                            }}<template v-if="memo.openUntil">
                                · open until {{ memo.openUntil }}</template
                            >
                        </p>
                    </div>
                    <StatusBadge
                        v-if="memo.acknowledgedOn"
                        status="acknowledged"
                        label="Read"
                        tone="success"
                    />
                    <StatusBadge
                        v-else-if="memo.status !== 'open'"
                        :status="memo.status"
                        :label="statusLabel[memo.status]"
                        tone="neutral"
                    />
                </div>

                <p
                    v-if="memo.replacementWithdrawnOn"
                    class="text-sm"
                    data-test="memo-applies-again"
                >
                    Its replacement was withdrawn on
                    {{ memo.replacementWithdrawnOn }}; this memo applies again.
                </p>
                <p
                    v-if="memo.status === 'replaced' && !memo.acknowledgedOn"
                    class="text-sm"
                    data-test="memo-replaced"
                >
                    <template v-if="memo.replacedBy"
                        >This memo was replaced by a newer memo. Please read the
                        newer one.</template
                    ><template v-else
                        >This memo was replaced and no longer takes
                        reads.</template
                    >
                </p>
                <p
                    v-else-if="memo.status === 'closed' && !memo.acknowledgedOn"
                    class="text-sm text-muted-foreground"
                    data-test="memo-closed"
                >
                    This memo is closed. It no longer takes new reads.
                </p>
                <p
                    v-if="memo.acknowledgedOn"
                    class="text-sm text-muted-foreground"
                >
                    You read this on {{ memo.acknowledgedOn }}.
                </p>

                <div
                    v-if="bodies[memo.id] !== undefined"
                    class="rounded-lg border bg-muted/40 p-3 text-sm break-words whitespace-pre-wrap"
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
                    <Button
                        variant="outline"
                        class="max-lg:min-h-11"
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
                    </Button>
                    <Button
                        v-if="bodies[memo.id] !== undefined"
                        variant="outline"
                        class="max-lg:min-h-11"
                        @click="hideBody(memo.id)"
                        >Hide</Button
                    >
                    <Button
                        v-if="memo.hasPdf"
                        as-child
                        variant="outline"
                        class="max-lg:min-h-11"
                    >
                        <a
                            :href="pdfUrl(memo.id)"
                            :aria-label="`Open the PDF of ${memo.title}`"
                            data-test="memo-pdf"
                            @click="markPdfOpened(memo.id)"
                            >Open the PDF</a
                        >
                    </Button>
                    <template
                        v-if="memo.status === 'open' && !memo.acknowledgedOn"
                    >
                        <Button
                            class="max-lg:min-h-11"
                            :disabled="
                                !ready(memo) ||
                                (ack.processing && ack.id === memo.id)
                            "
                            :aria-describedby="
                                ready(memo) ? undefined : `hint-${memo.id}`
                            "
                            data-test="memo-acknowledge"
                            @click="acknowledge(memo)"
                        >
                            {{
                                ack.processing && ack.id === memo.id
                                    ? 'Saving…'
                                    : 'I have read this'
                            }}
                        </Button>
                        <span
                            v-if="!ready(memo)"
                            :id="`hint-${memo.id}`"
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
            aria-labelledby="manage-memos-title"
            class="flex flex-col gap-3"
            data-test="manage-memos"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="manage-memos-title" class="text-base font-semibold">
                    Published memos
                </h2>
                <Button
                    variant="outline"
                    class="max-lg:min-h-11"
                    data-test="memo-publish"
                    @click="startPublish()"
                    >Write a memo</Button
                >
            </div>
            <EmptyState
                v-if="!published.length"
                title="No memos yet."
                description="Write a memo and choose who it is for."
            />
            <article
                v-for="memo in published"
                :key="memo.id"
                class="flex flex-col gap-3 rounded-xl border bg-card p-4"
                :aria-labelledby="`manage-${memo.id}`"
                data-test="managed-memo"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h3
                            :id="`manage-${memo.id}`"
                            class="text-base font-semibold break-words"
                        >
                            {{ memo.title }}
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            {{ memo.publishedOn }} by {{ memo.publishedBy }} ·
                            for {{ memo.audienceLabel
                            }}<template v-if="memo.openUntil">
                                · open until {{ memo.openUntil }}</template
                            ><template v-if="memo.hasPdf"> · with PDF</template
                            ><template v-if="memo.replacesTitle">
                                · replaces "{{ memo.replacesTitle }}"</template
                            >
                        </p>
                    </div>
                    <div class="flex flex-col items-end gap-1">
                        <StatusBadge
                            v-if="memo.status !== 'open'"
                            :status="memo.status"
                            :label="statusLabel[memo.status]"
                            tone="neutral"
                        />
                        <p
                            class="text-sm font-medium tabular-nums"
                            data-test="memo-count"
                        >
                            {{ memo.read }} of {{ memo.expected }} expected now
                            have read
                        </p>
                    </div>
                </div>
                <p
                    v-if="memo.replacementWithdrawnOn"
                    class="text-sm"
                    data-test="memo-applies-again"
                >
                    Its replacement was withdrawn on
                    {{ memo.replacementWithdrawnOn }}; this memo applies again.
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        class="max-lg:min-h-11"
                        :disabled="opening === memo.id"
                        @click="openMemo(memo, false)"
                        >Open</Button
                    >
                    <Button
                        v-if="memo.hasPdf"
                        as-child
                        variant="outline"
                        class="max-lg:min-h-11"
                    >
                        <a
                            :href="pdfUrl(memo.id)"
                            :aria-label="`Open the PDF of ${memo.title}`"
                            >Open the PDF</a
                        >
                    </Button>
                    <Button
                        variant="outline"
                        class="max-lg:min-h-11"
                        :aria-expanded="expanded === memo.id"
                        :aria-controls="`details-${memo.id}`"
                        @click="toggle(memo.id)"
                        >{{
                            expanded === memo.id
                                ? 'Hide who has not read'
                                : `Who hasn't read yet (${memo.notYet.length})`
                        }}</Button
                    >
                    <Button
                        v-if="
                            memo.status === 'open' || memo.status === 'closed'
                        "
                        variant="outline"
                        class="max-lg:min-h-11"
                        data-test="memo-replace"
                        @click="startPublish(memo.id)"
                        >Replace with a new memo</Button
                    >
                    <Button
                        variant="outline"
                        class="max-lg:min-h-11"
                        @click="askWithdraw(memo)"
                        >Withdraw</Button
                    >
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
                    class="rounded-lg border bg-muted/40 p-3 text-sm break-words whitespace-pre-wrap"
                    data-test="memo-body"
                >
                    {{ bodies[memo.id] }}
                </div>
                <div
                    v-if="expanded === memo.id"
                    :id="`details-${memo.id}`"
                    class="flex flex-col gap-2"
                >
                    <p
                        v-if="memo.status === 'replaced'"
                        class="text-sm"
                        data-test="memo-replaced-note"
                    >
                        Replaced by "{{ memo.replacedByTitle }}". The people
                        below are asked to read the newer memo.
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
                            class="py-2"
                        >
                            <span class="font-medium">{{ p.name }}</span>
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
                aria-labelledby="withdrawn-title"
                class="flex flex-col gap-2"
            >
                <h2 id="withdrawn-title" class="text-sm font-semibold">
                    Withdrawn
                </h2>
                <ul
                    class="flex flex-col divide-y rounded-xl border bg-card text-sm"
                >
                    <li
                        v-for="memo in withdrawn"
                        :key="memo.id"
                        class="px-4 py-3"
                    >
                        {{ memo.title }} · withdrawn {{ memo.withdrawnOn }} by
                        {{ memo.withdrawnBy
                        }}<template v-if="memo.withdrawnReason">
                            ({{ memo.withdrawnReason }})</template
                        >. {{ memo.read }} of {{ memo.expected }} had read it;
                        every read is kept.
                    </li>
                </ul>
            </section>
        </section>

        <Sheet v-model:open="form.open">
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-md"
                data-test="memo-sheet"
            >
                <SheetHeader>
                    <SheetTitle>Write a memo</SheetTitle>
                    <SheetDescription
                        >A published memo cannot be edited. To correct it,
                        publish a new memo that replaces it.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-6"
                    @submit.prevent="confirmPublish = true"
                >
                    <ErrorState v-if="failure" :message="failure" />
                    <div class="grid gap-1.5">
                        <Label for="memo-title">Title *</Label>
                        <Input
                            id="memo-title"
                            v-model="form.title"
                            maxlength="120"
                            class="max-lg:h-11"
                            aria-describedby="memo-title-help"
                            :aria-invalid="!!errors.title"
                        />
                        <p
                            id="memo-title-help"
                            class="text-xs text-muted-foreground"
                        >
                            Don't put a staff member's name, health or pay in
                            the title.
                        </p>
                        <InputError :message="errors.title" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="memo-body">Memo *</Label>
                        <textarea
                            id="memo-body"
                            v-model="form.body"
                            rows="8"
                            :maxlength="memos.options.maxBody"
                            class="w-full rounded-[10px] border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none aria-[invalid=true]:border-destructive"
                            aria-describedby="memo-body-help"
                            :aria-invalid="errors.body ? 'true' : undefined"
                        ></textarea>
                        <p
                            id="memo-body-help"
                            class="text-xs text-muted-foreground"
                        >
                            Plain text, {{ form.body.length }} of
                            {{ memos.options.maxBody }} characters.
                        </p>
                        <InputError :message="errors.body" />
                    </div>
                    <fieldset class="flex flex-col gap-1">
                        <legend class="mb-1 text-sm font-medium">
                            Branches (none ticked means all)
                        </legend>
                        <label
                            v-for="b in memos.options.branches"
                            :key="b.value"
                            class="flex min-h-11 items-center gap-2 text-sm lg:min-h-0"
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
                    <fieldset class="flex flex-col gap-1">
                        <legend class="mb-1 text-sm font-medium">
                            Roles (none ticked means all)
                        </legend>
                        <label
                            v-for="r in memos.options.roles"
                            :key="r.value"
                            class="flex min-h-11 items-center gap-2 text-sm lg:min-h-0"
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
                            With both branches and roles ticked, a person must
                            match a ticked branch and a ticked role.
                        </p>
                    </fieldset>
                    <p
                        class="text-sm font-medium"
                        role="status"
                        data-test="memo-audience-count"
                    >
                        This memo goes to: {{ audienceWords }} (about
                        {{ peopleWords }})
                    </p>
                    <InputError :message="errors.audience" />
                    <div class="grid gap-1.5">
                        <Label for="memo-until">Open until (optional)</Label>
                        <DateField
                            id="memo-until"
                            v-model="form.openUntil"
                            :min="memos.options.today"
                            :aria-invalid="!!errors.open_until"
                        />
                        <InputError :message="errors.open_until" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label id="memo-replaces-label"
                            >Replaces (optional)</Label
                        >
                        <OperationalSelect
                            :model-value="form.replaces"
                            :options="replaceOptions"
                            label="Replaces"
                            labelledby="memo-replaces-label"
                            trigger-class="max-lg:min-h-11"
                            @update:model-value="
                                (v) => (form.replaces = String(v))
                            "
                        />
                        <InputError :message="errors.replaces" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="memo-file">PDF file (optional)</Label>
                        <FileChooseButton
                            id="memo-file"
                            ref="fileInput"
                            accept="application/pdf"
                            label="Choose PDF"
                            :status="fileStatus"
                            status-id="memo-file-name"
                            aria-describedby="memo-file-help"
                            @change="onFile"
                        />
                        <p
                            id="memo-file-help"
                            class="text-xs text-muted-foreground"
                        >
                            PDF only, up to {{ memos.options.maxMb }} MB. People
                            open it before they can mark the memo as read.
                        </p>
                        <InputError :message="errors.file" />
                    </div>
                    <Button
                        type="submit"
                        class="max-lg:min-h-11"
                        :disabled="form.processing"
                        data-test="memo-save"
                    >
                        {{ form.processing ? 'Publishing…' : 'Publish memo' }}
                    </Button>
                </form>
            </SheetContent>
        </Sheet>

        <OperationalConfirmDialog
            :open="confirmPublish"
            title="Publish this memo?"
            :description="`${form.title}. It goes to ${audienceWords} (about ${peopleWords}). A published memo cannot be edited; to correct it you publish a new memo that replaces it.`"
            confirm-label="Publish memo"
            processing-label="Publishing…"
            :processing="form.processing"
            @update:open="(v: boolean) => (confirmPublish = v)"
            @confirm="sendForm"
        />

        <OperationalConfirmDialog
            :open="withdrawing !== null"
            title="Withdraw this memo?"
            :description="
                withdrawing
                    ? `${withdrawing.title}. Staff will no longer see it in their memos, and it takes no new reads. Every read already given is kept. It cannot come back; publish a new memo instead.`
                    : ''
            "
            destructive
            confirm-label="Withdraw"
            processing-label="Withdrawing…"
            :processing="withdrawBusy"
            :error="failure"
            @update:open="(v: boolean) => !v && (withdrawing = null)"
            @confirm="withdraw"
        >
            <div v-if="withdrawing" class="grid gap-1.5 px-1 pt-2">
                <Label id="withdraw-reason-label">Why *</Label>
                <OperationalSelect
                    :model-value="withdrawReason"
                    :options="memos.options.withdrawReasons"
                    label="Why"
                    labelledby="withdraw-reason-label"
                    placeholder="Choose a reason"
                    trigger-class="max-lg:min-h-11"
                    @update:model-value="(v) => (withdrawReason = String(v))"
                />
                <InputError :message="errors.reason" />
            </div>
        </OperationalConfirmDialog>
    </div>
</template>
