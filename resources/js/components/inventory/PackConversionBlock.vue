<script setup lang="ts">
// STK-52: the "Pack conversion" block of the Classic SKU edit screen (Daniel's look, 21 Nov). A person who may check a
// waiting change never receives its number from the server, so this block cannot show it to them.
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';

export type PackConversion = {
    baseUnit: string;
    expectedVersion: number;
    canPropose: boolean;
    current: {
        packUnit: string;
        basePerPack: number;
        version: number;
        checkedBy: string | null;
        checkedOn: string | null;
    } | null;
    waiting: {
        publicId: string;
        version: number;
        stop: boolean;
        proposedBy: string | null;
        proposedOn: string | null;
        mine: boolean;
        canCheck: boolean;
        canWithdraw: boolean;
        packUnit?: string | null;
        basePerPack?: number | null;
    } | null;
    history: {
        version: number;
        outcome: 'checked' | 'refused' | 'withdrawn';
        stop: boolean;
        by: string | null;
        on: string | null;
        packUnit?: string | null;
        basePerPack?: number | null;
    }[];
    legacyDiffers: boolean;
    legacy: { packSize: string; purchaseUnit: string } | null;
};

const props = defineProps<{
    skuPublicId: string;
    block: PackConversion;
    filters?: { q: string; status: string };
}>();
// The list comes back filtered as it was, so this SKU and its message stay on screen.
const back = () => ({
    return_q: props.filters?.q ?? '',
    return_status: props.filters?.status ?? 'active',
});

const proposal = useForm({
    expected_version: props.block.expectedVersion,
    idempotency_key: crypto.randomUUID(),
    pack_unit: '',
    base_per_pack: '',
});
const check = useForm({
    idempotency_key: crypto.randomUUID(),
    checked_base_per_pack: '',
});
const refusal = useForm({
    idempotency_key: crypto.randomUUID(),
    reason: '',
});
const decision = useForm({ idempotency_key: crypto.randomUUID() });
const stopping = ref(false);
const refusing = ref(false);
const withdrawing = ref(false);
const showHistory = ref(false);

const unit = computed(() => props.block.baseUnit);
const packWord = computed(() => proposal.pack_unit.trim() || 'pack');
const count = computed(() => Number(proposal.base_per_pack));
const proposalValid = computed(
    () =>
        proposal.pack_unit.trim() !== '' &&
        Number.isInteger(count.value) &&
        count.value >= 2 &&
        count.value <= 100000,
);
const reasons: Record<string, string> = {
    wrong_count: 'The count is wrong',
    wrong_unit: 'The pack unit is wrong',
    other: 'Another reason',
};
const outcomeWord: Record<string, string> = {
    checked: 'Checked',
    refused: 'Refused',
    withdrawn: 'Withdrawn',
};
const describe = (packUnit?: string | null, basePerPack?: number | null) =>
    packUnit && basePerPack
        ? `1 ${packUnit} = ${basePerPack} ${unit.value}`
        : '';

const fresh = () => crypto.randomUUID();
const propose = () =>
    proposal
        .transform((data) => ({
            ...data,
            ...back(),
            base_per_pack: Number(data.base_per_pack),
        }))
        .post(`/inventory/skus/${props.skuPublicId}/pack-conversions`, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => (proposal.idempotency_key = fresh()),
        });
const stop = () =>
    proposal
        .transform(() => ({
            ...back(),
            expected_version: props.block.expectedVersion,
            idempotency_key: proposal.idempotency_key,
            stop: true,
        }))
        .post(`/inventory/skus/${props.skuPublicId}/pack-conversions`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => (stopping.value = false),
            onFinish: () => (proposal.idempotency_key = fresh()),
        });
const submitCheck = () =>
    check
        .transform((data) => ({
            ...data,
            ...back(),
            checked_base_per_pack:
                data.checked_base_per_pack === ''
                    ? null
                    : Number(data.checked_base_per_pack),
        }))
        .post(
            `/inventory/pack-conversions/${props.block.waiting?.publicId}/check`,
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => {
                    check.idempotency_key = fresh();
                    check.checked_base_per_pack = '';
                },
            },
        );
const submitRefusal = () =>
    refusal
        .transform((data) => ({ ...data, ...back() }))
        .post(
            `/inventory/pack-conversions/${props.block.waiting?.publicId}/refuse`,
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => (refusing.value = false),
                onFinish: () => (refusal.idempotency_key = fresh()),
            },
        );
const withdraw = () =>
    decision
        .transform((data) => ({ ...data, ...back() }))
        .post(
            `/inventory/pack-conversions/${props.block.waiting?.publicId}/withdraw`,
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => (withdrawing.value = false),
                onFinish: () => (decision.idempotency_key = fresh()),
            },
        );
</script>

<template>
    <fieldset class="grid gap-3" data-testid="pack-conversion">
        <legend class="mb-1 text-sm font-semibold">Pack conversion</legend>
        <!-- A wrong blind count refuses the change, so its form is gone: the message shows here instead. -->
        <p
            v-if="!block.waiting && check.errors.checked_base_per_pack"
            role="alert"
            class="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive"
            data-testid="pack-conversion-refused"
        >
            {{ check.errors.checked_base_per_pack }}
        </p>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <template v-if="block.current">
                    <p
                        class="font-medium"
                        data-testid="pack-conversion-current"
                    >
                        {{
                            describe(
                                block.current.packUnit,
                                block.current.basePerPack,
                            )
                        }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Version {{ block.current.version }} · checked by
                        {{ block.current.checkedBy ?? 'Staff member' }} on
                        {{ block.current.checkedOn }}
                    </p>
                </template>
                <p
                    v-else
                    class="font-medium"
                    data-testid="pack-conversion-none"
                >
                    None
                </p>
            </div>
            <Button
                v-if="block.history.length"
                type="button"
                variant="ghost"
                class="min-h-11 lg:min-h-9"
                :aria-expanded="showHistory"
                @click="showHistory = !showHistory"
                >{{
                    showHistory
                        ? 'Hide earlier versions'
                        : 'Show earlier versions'
                }}</Button
            >
        </div>
        <ul
            v-if="showHistory"
            class="space-y-1 text-sm"
            data-testid="pack-conversion-history"
        >
            <li
                v-for="entry in block.history"
                :key="entry.version"
                class="flex flex-wrap items-center gap-2"
            >
                <span
                    class="size-2 rounded-full"
                    :class="
                        entry.outcome === 'checked'
                            ? 'bg-success'
                            : 'bg-muted-foreground'
                    "
                    aria-hidden="true"
                ></span>
                <span class="font-medium">{{
                    outcomeWord[entry.outcome]
                }}</span>
                <span>Version {{ entry.version }}</span>
                <span v-if="entry.stop">· stop using pack conversion</span>
                <span v-else-if="entry.packUnit"
                    >· {{ describe(entry.packUnit, entry.basePerPack) }}</span
                >
                <span class="text-muted-foreground"
                    >· {{ entry.by ?? 'Staff member' }} on {{ entry.on }}</span
                >
            </li>
        </ul>
        <p
            class="text-sm text-muted-foreground"
            data-testid="pack-conversion-limit"
        >
            Stock stays counted in {{ unit }}. Receiving, opening balances and
            counts are typed in {{ unit }}: 10 bottles is 600 {{ unit }} when a
            bottle holds 60.
        </p>

        <!-- A change waiting for a check. -->
        <div
            v-if="block.waiting"
            class="space-y-3 rounded-md border border-warning bg-warning/10 p-3 text-sm"
            data-testid="pack-conversion-waiting"
        >
            <p v-if="block.waiting.canCheck" class="font-medium">
                Waiting for a check:
                {{
                    block.waiting.stop
                        ? 'stop using the pack conversion'
                        : 'a change to the pack size'
                }}, proposed by
                {{ block.waiting.proposedBy ?? 'Staff member' }} on
                {{ block.waiting.proposedOn }}.
            </p>
            <p v-else class="font-medium">
                Waiting for a check:
                {{
                    block.waiting.stop
                        ? 'stop using the pack conversion'
                        : describe(
                              block.waiting.packUnit,
                              block.waiting.basePerPack,
                          )
                }}, proposed by
                {{
                    block.waiting.mine
                        ? 'you'
                        : (block.waiting.proposedBy ?? 'Staff member')
                }}
                on {{ block.waiting.proposedOn }}.
            </p>

            <form
                v-if="block.waiting.canCheck"
                class="space-y-2"
                @submit.prevent="submitCheck"
            >
                <label v-if="!block.waiting.stop" class="grid max-w-sm gap-1"
                    >How many {{ unit }} are in one pack? Count them yourself
                    and type the number. Count the physical pack; do not copy
                    the legacy pack fields.
                    <Input
                        v-model="check.checked_base_per_pack"
                        class="h-11 lg:h-9"
                        inputmode="numeric"
                        autocomplete="off"
                        data-testid="pack-conversion-blind"
                    />
                    <InputError :message="check.errors.checked_base_per_pack" />
                </label>
                <InputError
                    :message="
                        (check.errors as Record<string, string>).pack_conversion
                    "
                />
                <div class="flex flex-wrap gap-2">
                    <Button
                        type="submit"
                        class="min-h-11 lg:min-h-9"
                        :disabled="
                            check.processing ||
                            (!block.waiting.stop &&
                                check.checked_base_per_pack === '')
                        "
                        >{{
                            block.waiting.stop ? 'Confirm the stop' : 'Check'
                        }}</Button
                    >
                    <Button
                        type="button"
                        variant="outline"
                        class="min-h-11 lg:min-h-9"
                        @click="refusing = true"
                        >Refuse</Button
                    >
                </div>
            </form>
            <div v-if="block.waiting.canWithdraw">
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-11 lg:min-h-9"
                    @click="withdrawing = true"
                    >Withdraw</Button
                >
            </div>
        </div>

        <!-- Propose a change. -->
        <form
            v-if="block.canPropose"
            class="grid gap-3 sm:grid-cols-2"
            @submit.prevent="propose"
        >
            <label class="grid min-w-0 gap-1 text-sm"
                >Pack unit
                <Input
                    v-model="proposal.pack_unit"
                    class="h-11 lg:h-9"
                    maxlength="40"
                    placeholder="bottle"
                />
                <InputError :message="proposal.errors.pack_unit" />
            </label>
            <label class="grid min-w-0 gap-1 text-sm"
                >How many {{ unit }} in one {{ packWord }}?
                <Input
                    v-model="proposal.base_per_pack"
                    class="h-11 lg:h-9"
                    inputmode="numeric"
                    autocomplete="off"
                />
                <InputError :message="proposal.errors.base_per_pack" />
            </label>
            <p
                class="text-sm sm:col-span-2"
                data-testid="pack-conversion-preview"
            >
                <template v-if="proposalValid"
                    >Dispensary will hand out whole {{ packWord }}s of
                    {{ count }} {{ unit }}, once someone else has checked
                    this.</template
                >
                <template v-else
                    >Stock stays counted in {{ unit }}. This only lets
                    Dispensary hand out whole packs.</template
                >
            </p>
            <InputError
                class="sm:col-span-2"
                :message="
                    (proposal.errors as Record<string, string>).expected_version
                "
            />
            <div class="flex flex-wrap items-center gap-2 sm:col-span-2">
                <Button
                    type="submit"
                    class="min-h-11 lg:min-h-9"
                    :disabled="!proposalValid || proposal.processing"
                    >Propose</Button
                >
                <span
                    v-if="!proposalValid"
                    class="text-sm text-muted-foreground"
                    >Enter the pack unit and the number of {{ unit }} to
                    continue.</span
                >
                <Button
                    v-if="block.current"
                    type="button"
                    variant="outline"
                    class="min-h-11 lg:min-h-9"
                    @click="stopping = true"
                    >Stop using pack conversion</Button
                >
            </div>
        </form>

        <p
            v-if="block.legacyDiffers && block.legacy"
            class="text-sm text-muted-foreground"
            data-testid="pack-conversion-legacy"
        >
            The legacy pack fields say {{ block.legacy.packSize }}
            {{ block.legacy.purchaseUnit }}. Dispensary uses the checked
            conversion above.
        </p>

        <OperationalConfirmDialog
            :open="stopping"
            title="Stop using the pack conversion?"
            :description="`Dispensary will go back to ${unit} for this item once someone else has checked this.`"
            confirm-label="Propose the stop"
            processing-label="Proposing…"
            :processing="proposal.processing"
            :error="(proposal.errors as Record<string, string>).stop ?? ''"
            @update:open="(open: boolean) => (stopping = open)"
            @confirm="stop"
        />
        <OperationalConfirmDialog
            :open="withdrawing"
            title="Withdraw this change?"
            description="The pack conversion in use stays as it is. Nothing is deleted."
            confirm-label="Withdraw"
            processing-label="Withdrawing…"
            :processing="decision.processing"
            @update:open="(open: boolean) => (withdrawing = open)"
            @confirm="withdraw"
        />
        <OperationalConfirmDialog
            :open="refusing"
            title="Refuse this change?"
            description="The pack conversion in use stays as it is."
            confirm-label="Refuse"
            processing-label="Refusing…"
            destructive
            :processing="refusal.processing"
            :confirm-disabled="refusal.reason === ''"
            confirm-disabled-reason="Pick a reason to continue."
            :error="refusal.errors.reason ?? ''"
            @update:open="(open: boolean) => (refusing = open)"
            @confirm="submitRefusal"
        >
            <label class="grid gap-1 text-sm"
                >Reason
                <select
                    v-model="refusal.reason"
                    class="h-11 rounded-md border bg-background px-3"
                    data-testid="pack-conversion-refuse-reason"
                >
                    <option value="" disabled>Pick a reason</option>
                    <option
                        v-for="(label, value) in reasons"
                        :key="value"
                        :value="value"
                    >
                        {{ label }}
                    </option>
                </select>
            </label>
        </OperationalConfirmDialog>
    </fieldset>
</template>
