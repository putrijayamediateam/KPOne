<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type PanelRow = {
    id: number;
    code: string;
    name: string;
    verificationMode: 'url' | 'remark';
    verificationUrl: string | null;
    verificationRemark: string | null;
    priceTiers: string[];
    isActive: boolean;
    lockVersion: number;
    editUrl: string;
};

const props = defineProps<{
    tab: 'all' | 'active' | 'archived';
    q: string;
    counts: { all: number; active: number; archived: number };
    panels: PanelRow[];
    pagination: { currentPage: number; lastPage: number; total: number };
    selfPayMethods: string[];
    claimMessage: {
        body: string;
        lockVersion: number;
        fields: { key: string; label: string }[];
        maxLength: number;
    };
    can: { managePaymentMethods: boolean };
}>();

const search = ref(props.q);
const menuFor = ref<number | null>(null);

const go = (params: { tab?: string; q?: string; page?: number }) => {
    router.get(
        '/checkout-settings',
        {
            tab: params.tab ?? props.tab,
            q: (params.q ?? search.value) || undefined,
            page: params.page && params.page > 1 ? params.page : undefined,
        },
        { preserveScroll: true, preserveState: true },
    );
};

const toggle = (panel: PanelRow) => {
    router.post(
        `/checkout-settings/panels/${panel.id}/active`,
        { active: !panel.isActive, lock_version: panel.lockVersion },
        { preserveScroll: true },
    );
};

const messageForm = useForm({
    body: props.claimMessage.body,
    lock_version: props.claimMessage.lockVersion,
});
const messageBox = ref<HTMLTextAreaElement | null>(null);
const insertField = (key: string) => {
    const token = `{${key}}`;
    const box = messageBox.value;
    const start = box?.selectionStart ?? messageForm.body.length;
    const end = box?.selectionEnd ?? start;
    messageForm.body =
        messageForm.body.slice(0, start) + token + messageForm.body.slice(end);
    requestAnimationFrame(() => {
        box?.focus();
        box?.setSelectionRange(start + token.length, start + token.length);
    });
};
const saveMessage = () =>
    messageForm.patch('/checkout-settings/claim-message', {
        preserveScroll: true,
        onSuccess: () => {
            messageForm.lock_version = props.claimMessage.lockVersion;
        },
    });

const tabs: { key: 'all' | 'active' | 'archived'; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'active', label: 'Active' },
    { key: 'archived', label: 'Archived' },
];
</script>

<template>
    <Head title="Checkout settings" />
    <main
        class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 px-4 py-6 md:px-6"
    >
        <PageHeader
            title="Checkout settings"
            description="Panels and the payment methods used at checkout."
        />

        <section class="rounded-xl border bg-card p-4" data-testid="panel-list">
            <h2 class="text-base font-semibold">Panel payment method</h2>
            <p class="text-sm text-muted-foreground">
                Add, edit, archive or restore your Panels. A Panel that has
                visits or claims is archived, never deleted.
            </p>

            <div
                class="mt-4 flex flex-wrap items-center justify-between gap-3 border-b"
            >
                <div class="flex gap-5" role="tablist">
                    <button
                        v-for="item in tabs"
                        :key="item.key"
                        type="button"
                        role="tab"
                        :aria-selected="tab === item.key"
                        class="-mb-px border-b-2 px-1 pb-2 text-sm"
                        :class="
                            tab === item.key
                                ? 'border-foreground font-semibold'
                                : 'border-transparent text-muted-foreground'
                        "
                        @click="go({ tab: item.key, page: 1 })"
                    >
                        {{ item.label }} ({{ counts[item.key] }})
                    </button>
                </div>
                <div class="flex items-center gap-2 pb-2">
                    <form
                        class="flex items-center gap-2"
                        @submit.prevent="go({ page: 1 })"
                    >
                        <Input
                            v-model="search"
                            type="search"
                            placeholder="Search Panel"
                            maxlength="100"
                            aria-label="Search Panel"
                            class="h-9 w-48"
                        />
                    </form>
                    <Button as-child>
                        <Link href="/checkout-settings/panels/create">
                            Add panel
                        </Link>
                    </Button>
                </div>
            </div>

            <div
                v-if="panels.length === 0"
                class="mt-4 rounded-lg border border-dashed p-5 text-sm text-muted-foreground"
            >
                No Panels found.
            </div>
            <div v-else class="mt-2 overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead>
                        <tr class="border-b text-xs text-muted-foreground">
                            <th class="py-2 pr-3 font-medium">NAME</th>
                            <th class="py-2 pr-3 font-medium">VERIFY LINK</th>
                            <th class="py-2 pr-3 font-medium">PRICE TIER</th>
                            <th class="py-2 pr-3 font-medium">STATUS</th>
                            <th class="py-2 font-medium">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="panel in panels"
                            :key="panel.id"
                            class="border-b last:border-0"
                        >
                            <td class="py-3 pr-3">
                                <div class="font-medium">{{ panel.name }}</div>
                                <div
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    {{ panel.code }}
                                </div>
                            </td>
                            <td class="max-w-[22rem] py-3 pr-3 break-all">
                                <a
                                    v-if="panel.verificationUrl"
                                    :href="panel.verificationUrl"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="underline"
                                >
                                    {{ panel.verificationUrl }}
                                </a>
                                <span
                                    v-else-if="panel.verificationRemark"
                                    class="text-muted-foreground"
                                >
                                    {{ panel.verificationRemark }}
                                </span>
                            </td>
                            <td class="py-3 pr-3">
                                <div class="flex flex-wrap gap-1">
                                    <span
                                        v-for="tier in panel.priceTiers"
                                        :key="tier"
                                        class="rounded bg-muted px-2 py-0.5 text-xs"
                                    >
                                        {{ tier }}
                                    </span>
                                </div>
                            </td>
                            <td class="py-3 pr-3">
                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="panel.isActive"
                                    :aria-label="
                                        panel.isActive
                                            ? `Archive ${panel.name}`
                                            : `Restore ${panel.name}`
                                    "
                                    class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors"
                                    :class="
                                        panel.isActive
                                            ? 'bg-green-600'
                                            : 'bg-muted-foreground/40'
                                    "
                                    @click="toggle(panel)"
                                >
                                    <span
                                        class="inline-block size-5 rounded-full bg-white transition-transform"
                                        :class="
                                            panel.isActive
                                                ? 'translate-x-5'
                                                : 'translate-x-0.5'
                                        "
                                    />
                                </button>
                            </td>
                            <td class="relative py-3">
                                <button
                                    type="button"
                                    class="rounded px-2 py-1 hover:bg-muted"
                                    :aria-label="`Actions for ${panel.name}`"
                                    @click="
                                        menuFor =
                                            menuFor === panel.id
                                                ? null
                                                : panel.id
                                    "
                                >
                                    •••
                                </button>
                                <div
                                    v-if="menuFor === panel.id"
                                    class="absolute top-10 right-0 z-10 w-36 rounded-lg border bg-card p-1 shadow-md"
                                >
                                    <Link
                                        :href="panel.editUrl"
                                        class="block rounded px-3 py-2 text-sm hover:bg-muted"
                                    >
                                        Edit
                                    </Link>
                                    <button
                                        type="button"
                                        class="block w-full rounded px-3 py-2 text-left text-sm hover:bg-muted"
                                        @click="
                                            menuFor = null;
                                            toggle(panel);
                                        "
                                    >
                                        {{
                                            panel.isActive
                                                ? 'Archive'
                                                : 'Restore'
                                        }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="pagination.lastPage > 1"
                class="mt-3 flex items-center justify-end gap-2 text-sm"
            >
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="pagination.currentPage <= 1"
                    @click="go({ page: pagination.currentPage - 1 })"
                >
                    Previous
                </Button>
                <span>
                    {{ pagination.currentPage }} / {{ pagination.lastPage }}
                </span>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="pagination.currentPage >= pagination.lastPage"
                    @click="go({ page: pagination.currentPage + 1 })"
                >
                    Next
                </Button>
            </div>
        </section>

        <section
            class="rounded-xl border bg-card p-4"
            data-testid="claim-message"
        >
            <h2 class="text-base font-semibold">Claim message template</h2>
            <p class="text-sm text-muted-foreground">
                A pre-filled message for a single claim. Panel Officers copy it
                from the claim page and send it to the Panel themselves; nothing
                is sent from KPOne. Plain text only.
            </p>
            <form class="mt-3 grid gap-3" @submit.prevent="saveMessage">
                <textarea
                    ref="messageBox"
                    v-model="messageForm.body"
                    rows="8"
                    :maxlength="claimMessage.maxLength"
                    class="w-full rounded-md border bg-background p-3 text-sm"
                    placeholder="Type the message, then insert smart fields below"
                    aria-label="Claim message"
                />
                <div class="flex justify-between text-xs text-muted-foreground">
                    <span>These fields are filled in from the claim:</span>
                    <span>
                        Characters: {{ messageForm.body.length }} /
                        {{ claimMessage.maxLength }}
                    </span>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="field in claimMessage.fields"
                        :key="field.key"
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs hover:bg-muted"
                        @click="insertField(field.key)"
                    >
                        {{ field.label }}
                    </button>
                </div>
                <InputError :message="messageForm.errors.body" />
                <InputError :message="messageForm.errors.lock_version" />
                <div>
                    <Button type="submit" :disabled="messageForm.processing">
                        Save message
                    </Button>
                </div>
            </form>
        </section>

        <section class="rounded-xl border bg-card p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-base font-semibold">
                        Self-pay payment method
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Payment methods available for self-pay checkout.
                    </p>
                </div>
                <Button
                    v-if="can.managePaymentMethods"
                    variant="outline"
                    as-child
                >
                    <Link href="/payment-methods">Manage payment methods</Link>
                </Button>
            </div>
            <ul class="mt-3 divide-y text-sm">
                <li
                    v-for="method in selfPayMethods"
                    :key="method"
                    class="flex items-center justify-between py-2"
                >
                    <span>{{ method }}</span>
                    <span class="rounded bg-muted px-2 py-0.5 text-xs">
                        SELF PAY
                    </span>
                </li>
                <li
                    v-if="selfPayMethods.length === 0"
                    class="py-2 text-muted-foreground"
                >
                    No active payment methods.
                </li>
            </ul>
        </section>
    </main>
</template>
