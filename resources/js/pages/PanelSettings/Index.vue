<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Ellipsis } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { CompactPagination } from '@/components/ui/pagination';
import { EmptyState } from '@/components/ui/state';
import { OperationalTable } from '@/components/ui/table';
import { OperationalTabs } from '@/components/ui/tabs';

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

const tabs = computed(() => [
    { value: 'all', label: 'All', count: props.counts.all },
    { value: 'active', label: 'Active', count: props.counts.active },
    { value: 'archived', label: 'Archived', count: props.counts.archived },
]);
</script>

<template>
    <Head title="Checkout settings" />
    <main
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-5 px-4 py-6 md:px-6"
    >
        <PageHeader
            title="Checkout settings"
            description="Panels and the payment methods used at checkout."
        >
            <template #actions>
                <Button as-child class="max-lg:h-11">
                    <Link href="/checkout-settings/panels/create">
                        Add panel
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <section class="grid gap-4" data-testid="panel-list">
            <div>
                <h2 class="text-base font-semibold">Panel payment method</h2>
                <p class="text-sm text-muted-foreground">
                    Add, edit, archive or restore your Panels. A Panel that has
                    visits or claims is archived, never deleted.
                </p>
            </div>

            <OperationalTabs
                :model-value="tab"
                :tabs="tabs"
                label="Panel status"
                @update:model-value="
                    (value) => go({ tab: value as string, page: 1 })
                "
            />
            <form class="flex" @submit.prevent="go({ page: 1 })">
                <Input
                    v-model="search"
                    type="search"
                    placeholder="Search Panel"
                    maxlength="100"
                    aria-label="Search Panel"
                    class="w-full rounded-lg max-lg:h-11 max-lg:text-base sm:max-w-xs"
                />
            </form>

            <EmptyState v-if="panels.length === 0" title="No Panels found." />
            <template v-else>
                <!-- Phones and tablets: one card per Panel. -->
                <ul
                    class="divide-y rounded-xl border bg-card lg:hidden"
                    aria-label="Panels"
                >
                    <li
                        v-for="panel in panels"
                        :key="panel.id"
                        class="grid gap-2 p-4 text-sm"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-medium wrap-anywhere">
                                    {{ panel.name }}
                                </p>
                                <p
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    {{ panel.code }}
                                </p>
                            </div>
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="shrink-0 max-lg:size-11"
                                        :aria-label="`More actions for ${panel.name}`"
                                    >
                                        <Ellipsis
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem as-child>
                                        <Link :href="panel.editUrl">Edit</Link>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem @select="toggle(panel)">
                                        {{
                                            panel.isActive
                                                ? 'Archive'
                                                : 'Restore'
                                        }}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                        <dl
                            class="grid grid-cols-[6rem_minmax(0,1fr)] items-center gap-x-3 gap-y-2 text-xs"
                        >
                            <dt class="text-muted-foreground">Verify link</dt>
                            <dd class="break-all">
                                <a
                                    v-if="panel.verificationUrl"
                                    :href="panel.verificationUrl"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-block py-2 underline"
                                >
                                    {{ panel.verificationUrl }}
                                </a>
                                <span
                                    v-else-if="panel.verificationRemark"
                                    class="text-muted-foreground"
                                >
                                    {{ panel.verificationRemark }}
                                </span>
                            </dd>
                            <dt class="text-muted-foreground">Price tier</dt>
                            <dd class="flex flex-wrap gap-1">
                                <Badge
                                    v-for="tier in panel.priceTiers"
                                    :key="tier"
                                    variant="outline"
                                >
                                    {{ tier }}
                                </Badge>
                            </dd>
                            <dt class="text-muted-foreground">Status</dt>
                            <dd>
                                <div class="flex items-center gap-3">
                                    <button
                                        type="button"
                                        role="switch"
                                        :aria-checked="panel.isActive"
                                        :aria-label="
                                            panel.isActive
                                                ? `Archive ${panel.name}`
                                                : `Restore ${panel.name}`
                                        "
                                        class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full ring-1 ring-border transition-colors after:absolute after:inset-x-0 after:-inset-y-2 after:content-[''] disabled:opacity-50"
                                        :class="
                                            panel.isActive
                                                ? 'bg-success'
                                                : 'bg-muted-foreground/40'
                                        "
                                        @click="toggle(panel)"
                                    >
                                        <span
                                            class="size-5 rounded-full bg-white shadow-sm transition-transform"
                                            :class="
                                                panel.isActive
                                                    ? 'translate-x-6'
                                                    : 'translate-x-1'
                                            "
                                        />
                                    </button>
                                    <span class="text-sm">{{
                                        panel.isActive ? 'Active' : 'Archived'
                                    }}</span>
                                </div>
                            </dd>
                        </dl>
                    </li>
                </ul>
                <OperationalTable
                    class="hidden lg:block"
                    label="Panels"
                    :columns="5"
                    min-width="860px"
                >
                    <template #head>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Verify link</th>
                            <th scope="col">Price tier</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </template>
                    <template #body>
                        <tr v-for="panel in panels" :key="panel.id">
                            <td>
                                <div class="font-medium">{{ panel.name }}</div>
                                <div
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    {{ panel.code }}
                                </div>
                            </td>
                            <td class="max-w-[18rem] break-all">
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
                            <td>
                                <div class="flex flex-wrap gap-1">
                                    <Badge
                                        v-for="tier in panel.priceTiers"
                                        :key="tier"
                                        variant="outline"
                                    >
                                        {{ tier }}
                                    </Badge>
                                </div>
                            </td>
                            <td>
                                <div class="flex items-center gap-3">
                                    <button
                                        type="button"
                                        role="switch"
                                        :aria-checked="panel.isActive"
                                        :aria-label="
                                            panel.isActive
                                                ? `Archive ${panel.name}`
                                                : `Restore ${panel.name}`
                                        "
                                        class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full ring-1 ring-border transition-colors after:absolute after:inset-x-0 after:-inset-y-2 after:content-[''] disabled:opacity-50"
                                        :class="
                                            panel.isActive
                                                ? 'bg-success'
                                                : 'bg-muted-foreground/40'
                                        "
                                        @click="toggle(panel)"
                                    >
                                        <span
                                            class="size-5 rounded-full bg-white shadow-sm transition-transform"
                                            :class="
                                                panel.isActive
                                                    ? 'translate-x-6'
                                                    : 'translate-x-1'
                                            "
                                        />
                                    </button>
                                    <span class="text-sm">{{
                                        panel.isActive ? 'Active' : 'Archived'
                                    }}</span>
                                </div>
                            </td>
                            <td>
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            :aria-label="`More actions for ${panel.name}`"
                                        >
                                            <Ellipsis
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem as-child>
                                            <Link :href="panel.editUrl"
                                                >Edit</Link
                                            >
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            @select="toggle(panel)"
                                        >
                                            {{
                                                panel.isActive
                                                    ? 'Archive'
                                                    : 'Restore'
                                            }}
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </td>
                        </tr>
                    </template>
                </OperationalTable>
            </template>

            <CompactPagination
                v-if="pagination.lastPage > 1"
                class="justify-end"
                :current-page="pagination.currentPage"
                :last-page="pagination.lastPage"
                :total="counts[tab]"
                @change="(value) => go({ page: value })"
            />
        </section>

        <section
            class="rounded-xl border bg-card p-4 md:p-5"
            data-testid="claim-message"
        >
            <h2 class="text-base font-semibold">Claim message template</h2>
            <p class="text-sm text-muted-foreground">
                A pre-filled message for a single claim. Panel Officers copy it
                from the claim page and send it to the Panel themselves; nothing
                is sent from KPOne. Plain text only.
            </p>
            <form class="mt-4 grid gap-3" @submit.prevent="saveMessage">
                <div class="grid gap-1.5">
                    <textarea
                        ref="messageBox"
                        v-model="messageForm.body"
                        rows="8"
                        :maxlength="claimMessage.maxLength"
                        class="w-full rounded-lg border border-input bg-background p-3 text-base outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/40 lg:text-sm dark:bg-field-fill/30"
                        placeholder="Type the message, then insert smart fields below"
                        aria-label="Claim message"
                    />
                    <p
                        class="text-right text-xs text-muted-foreground tabular-nums"
                    >
                        Characters: {{ messageForm.body.length }} /
                        {{ claimMessage.maxLength }}
                    </p>
                </div>
                <p class="text-xs text-muted-foreground">
                    These fields are filled in from the claim:
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-for="field in claimMessage.fields"
                        :key="field.key"
                        type="button"
                        variant="outline"
                        size="sm"
                        class="rounded-full max-lg:h-11"
                        @click="insertField(field.key)"
                    >
                        {{ field.label }}
                    </Button>
                </div>
                <InputError :message="messageForm.errors.body" />
                <InputError :message="messageForm.errors.lock_version" />
                <div class="flex justify-end">
                    <Button
                        type="submit"
                        variant="outline"
                        class="max-lg:h-11 max-sm:w-full"
                        :disabled="messageForm.processing"
                    >
                        Save message
                    </Button>
                </div>
            </form>
        </section>

        <section class="rounded-xl border bg-card p-4 md:p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
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
                    class="max-lg:h-11 max-sm:w-full"
                >
                    <Link href="/payment-methods">Manage payment methods</Link>
                </Button>
            </div>
            <ul class="mt-3 divide-y text-sm">
                <li
                    v-for="method in selfPayMethods"
                    :key="method"
                    class="flex min-h-11 items-center justify-between py-2.5"
                >
                    <span>{{ method }}</span>
                    <Badge variant="outline">SELF PAY</Badge>
                </li>
            </ul>
            <EmptyState
                v-if="selfPayMethods.length === 0"
                class="mt-3"
                title="No active payment methods."
            />
        </section>
    </main>
</template>
