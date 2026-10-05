<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type ApproverRow = {
    id: number;
    name: string;
    email: string;
    canPanelApprove: boolean;
    canDefermentApprove: boolean;
    panelLimitSen: number | null;
    defermentLimitSen: number | null;
};

const props = defineProps<{
    branch: { id: number; name: string };
    approvers: ApproverRow[];
}>();

const fieldErrors = reactive<Record<string, string | undefined>>({});
const formValues = reactive<Record<string, string>>({});

const keyOf = (userId: number, capability: 'panel' | 'deferment') =>
    `${userId}:${capability}`;

const currentLimit = (row: ApproverRow, capability: 'panel' | 'deferment') =>
    capability === 'panel' ? row.panelLimitSen : row.defermentLimitSen;

for (const row of props.approvers) {
    const panelKey = keyOf(row.id, 'panel');

    if (!(panelKey in formValues)) {
        formValues[panelKey] =
            row.panelLimitSen === null ? '' : String(row.panelLimitSen);
    }

    const defermentKey = keyOf(row.id, 'deferment');

    if (!(defermentKey in formValues)) {
        formValues[defermentKey] =
            row.defermentLimitSen === null ? '' : String(row.defermentLimitSen);
    }
}

const saveLimit = (row: ApproverRow, capability: 'panel' | 'deferment') => {
    const key = keyOf(row.id, capability);
    fieldErrors[key] = undefined;
    router.post(
        '/billing-approval-limits',
        {
            expected_branch_id: props.branch.id,
            user_id: row.id,
            capability,
            limit_sen: formValues[key],
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                fieldErrors[key] =
                    errors.limit_sen ??
                    errors.user_id ??
                    errors.capability ??
                    errors.expected_branch_id;
            },
        },
    );
};

const clearLimit = (row: ApproverRow, capability: 'panel' | 'deferment') => {
    const key = keyOf(row.id, capability);
    fieldErrors[key] = undefined;
    router.post(
        '/billing-approval-limits/clear',
        {
            expected_branch_id: props.branch.id,
            user_id: row.id,
            capability,
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                fieldErrors[key] =
                    errors.user_id ??
                    errors.capability ??
                    errors.expected_branch_id;
            },
        },
    );
};
</script>

<template>
    <main
        class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 px-4 py-6 md:px-6"
    >
        <PageHeader
            title="Billing Approval Limits"
            description="Set branch-specific maximum approval amounts (in sen) for Panel and Pay later approvals."
        />

        <section class="rounded-xl border bg-card p-4">
            <p class="text-xs text-muted-foreground">
                Active branch: <span class="font-medium">{{ branch.name }}</span
                >.
            </p>
            <p class="mt-1 text-xs text-muted-foreground">
                A blank value means no configured limit for that approver and
                capability.
            </p>
        </section>

        <section class="rounded-xl border bg-card p-4">
            <h2 class="text-sm font-semibold">Approver limits</h2>
            <div
                v-if="approvers.length === 0"
                class="mt-3 rounded-lg border border-dashed p-5 text-sm text-muted-foreground"
            >
                No branch-assigned approvers were found for this branch.
            </div>
            <div v-else class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[920px] text-left text-sm">
                    <thead>
                        <tr class="border-b text-xs text-muted-foreground">
                            <th class="py-2 pr-3 font-medium">Approver</th>
                            <th class="py-2 pr-3 font-medium">
                                Panel approval limit (sen)
                            </th>
                            <th class="py-2 pr-3 font-medium">
                                Pay later approval limit (sen)
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in approvers"
                            :key="row.id"
                            class="border-b align-top last:border-0"
                        >
                            <td class="py-3 pr-3">
                                <p class="font-medium">{{ row.name }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ row.email }}
                                </p>
                            </td>
                            <td class="py-3 pr-3">
                                <template v-if="row.canPanelApprove">
                                    <div class="flex items-center gap-2">
                                        <Input
                                            v-model="
                                                formValues[
                                                    keyOf(row.id, 'panel')
                                                ]
                                            "
                                            type="number"
                                            min="0"
                                            max="999999999999"
                                            class="w-44"
                                        />
                                        <Button
                                            size="sm"
                                            @click="saveLimit(row, 'panel')"
                                        >
                                            Save
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            :disabled="
                                                currentLimit(row, 'panel') ===
                                                null
                                            "
                                            @click="clearLimit(row, 'panel')"
                                        >
                                            Clear
                                        </Button>
                                    </div>
                                    <InputError
                                        :message="
                                            fieldErrors[keyOf(row.id, 'panel')]
                                        "
                                    />
                                </template>
                                <p v-else class="text-xs text-muted-foreground">
                                    No Panel approval permission.
                                </p>
                            </td>
                            <td class="py-3 pr-3">
                                <template v-if="row.canDefermentApprove">
                                    <div class="flex items-center gap-2">
                                        <Input
                                            v-model="
                                                formValues[
                                                    keyOf(row.id, 'deferment')
                                                ]
                                            "
                                            type="number"
                                            min="0"
                                            max="999999999999"
                                            class="w-44"
                                        />
                                        <Button
                                            size="sm"
                                            @click="saveLimit(row, 'deferment')"
                                        >
                                            Save
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            :disabled="
                                                currentLimit(
                                                    row,
                                                    'deferment',
                                                ) === null
                                            "
                                            @click="
                                                clearLimit(row, 'deferment')
                                            "
                                        >
                                            Clear
                                        </Button>
                                    </div>
                                    <InputError
                                        :message="
                                            fieldErrors[
                                                keyOf(row.id, 'deferment')
                                            ]
                                        "
                                    />
                                </template>
                                <p v-else class="text-xs text-muted-foreground">
                                    No Pay later approval permission.
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</template>
