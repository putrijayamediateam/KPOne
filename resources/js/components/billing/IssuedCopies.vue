<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import EmptyState from '@/components/ui/state/EmptyState.vue';
import StatusBadge from '@/components/ui/status/StatusBadge.vue';
import type { IssuedCopy } from '@/types/billing';

/** BIL-5: every issued version of this visit's invoices and receipts, with a COPY reprint. */
const props = defineProps<{
    copies: IssuedCopy[];
    base: string;
    returnTo?: 'billing' | 'payments' | 'invoice';
}>();

const page = usePage();
const busy = ref<string | null>(null);
const confirming = ref<IssuedCopy | null>(null);

const reprint = (copy: IssuedCopy) => {
    busy.value = copy.publicId;
    router.post(
        `${props.base}/issued/${copy.publicId}/reprint`,
        {
            expected_branch_id: page.props.branchContext?.active?.id ?? 0,
            idempotency_key: crypto.randomUUID(),
            return_to: props.returnTo ?? 'billing',
        },
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = null;
                confirming.value = null;
            },
        },
    );
};
const ask = (copy: IssuedCopy) =>
    copy.latest ? reprint(copy) : (confirming.value = copy);
const name = (copy: IssuedCopy) =>
    `${copy.type === 'invoice' ? 'Invoice' : 'Receipt'} ${copy.number}`;
</script>

<template>
    <section class="space-y-2 border-t pt-3" data-testid="issued-copies">
        <div>
            <h3 class="font-medium">Issued copies</h3>
            <p class="text-xs text-muted-foreground">
                Each print is recorded. "Prints" counts presses of Print here,
                not sheets of paper.
            </p>
        </div>
        <EmptyState
            v-if="copies.length === 0"
            title="No copies issued yet."
            description="Press Print invoice to issue the first one."
        />
        <template v-else>
            <table class="hidden w-full text-left text-xs sm:table">
                <thead class="text-muted-foreground">
                    <tr>
                        <th class="py-1 font-medium">Document</th>
                        <th class="py-1 font-medium">Version</th>
                        <th class="py-1 font-medium">First printed as</th>
                        <th class="py-1 font-medium">Issued</th>
                        <th class="py-1 text-right font-medium">Prints</th>
                        <th class="py-1">
                            <span class="sr-only">Action</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="copy in copies"
                        :key="copy.publicId"
                        class="border-t"
                        data-testid="issued-copy"
                    >
                        <td class="py-2 break-words">{{ name(copy) }}</td>
                        <td class="tabular-nums">{{ copy.version }}</td>
                        <td><StatusBadge :status="copy.firstPrintedAs" /></td>
                        <td>{{ copy.issuedAt }}, {{ copy.issuedBy }}</td>
                        <td class="text-right tabular-nums">
                            {{ copy.prints }}
                        </td>
                        <td class="text-right">
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="busy !== null"
                                :aria-label="`Reprint ${name(copy)} version ${copy.version} as COPY`"
                                @click="ask(copy)"
                                >Reprint</Button
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
            <ul class="space-y-2 sm:hidden">
                <li
                    v-for="copy in copies"
                    :key="copy.publicId"
                    class="rounded-md border p-3 text-xs"
                >
                    <p class="flex flex-wrap items-center gap-2 font-medium">
                        {{ name(copy) }} · version {{ copy.version }}
                        <StatusBadge :status="copy.firstPrintedAs" />
                    </p>
                    <p class="mt-1 text-muted-foreground">
                        {{ copy.issuedAt }}, {{ copy.issuedBy }} ·
                        {{ copy.prints }} print(s)
                    </p>
                    <Button
                        class="mt-2 h-11 w-full"
                        variant="outline"
                        :disabled="busy !== null"
                        :aria-label="`Reprint ${name(copy)} version ${copy.version} as COPY`"
                        @click="ask(copy)"
                        >Reprint</Button
                    >
                </li>
            </ul>
        </template>
        <OperationalConfirmDialog
            :open="confirming !== null"
            :title="`Reprint version ${confirming?.version ?? ''}?`"
            description="This is not the current version. The paper will say COPY and that a later version exists."
            confirm-label="Reprint as COPY"
            processing-label="Issuing…"
            :processing="busy !== null"
            @update:open="(open) => (confirming = open ? confirming : null)"
            @confirm="confirming && reprint(confirming)"
        />
    </section>
</template>
