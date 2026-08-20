<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Users } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Staff', href: '/staff' }] },
});

defineProps<{
    staff: Array<{
        id: number;
        name: string;
        email: string;
        isActive: boolean;
        jobTitle: string | null;
        department: string | null;
        roles: string[];
        branches: Array<{
            id: number;
            code: string;
            name: string;
            isPrimary: boolean;
            assignmentType: string;
        }>;
    }>;
}>();

const label = (value: string) =>
    value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
</script>

<template>
    <Head title="Staff" />
    <main class="flex flex-1 flex-col gap-6 p-4 md:p-7">
        <div class="flex items-start gap-3">
            <div class="rounded-xl bg-emerald-100 p-2.5 text-emerald-800">
                <Users class="size-5" />
            </div>
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Staff</h1>
                <p class="text-sm text-muted-foreground">
                    Staff identities visible within your authorised scope.
                </p>
            </div>
        </div>
        <Card>
            <CardHeader
                ><CardTitle
                    >{{ staff.length }} staff account{{
                        staff.length === 1 ? '' : 's'
                    }}</CardTitle
                ><CardDescription
                    >Assignments are effective for the current date and branch
                    context.</CardDescription
                ></CardHeader
            >
            <CardContent class="p-0">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead
                            class="border-y bg-muted/40 text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="px-6 py-3">Staff member</th>
                                <th class="px-6 py-3">Department</th>
                                <th class="px-6 py-3">Role</th>
                                <th class="px-6 py-3">Branches</th>
                                <th class="px-6 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="member in staff"
                                :key="member.id"
                                class="hover:bg-muted/20"
                            >
                                <td class="px-6 py-4">
                                    <div class="font-medium">
                                        {{ member.name }}
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ member.email }}
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div>
                                        {{
                                            member.department ?? 'Not assigned'
                                        }}
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ member.jobTitle }}
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex flex-wrap gap-1">
                                        <Badge
                                            v-for="role in member.roles"
                                            :key="role"
                                            variant="secondary"
                                            >{{ label(role) }}</Badge
                                        >
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex flex-wrap gap-1">
                                        <Badge
                                            v-for="branch in member.branches"
                                            :key="branch.id"
                                            variant="outline"
                                            :class="
                                                branch.isPrimary &&
                                                'border-emerald-300 bg-emerald-50 text-emerald-800'
                                            "
                                            >{{ branch.code
                                            }}<span v-if="branch.isPrimary">
                                                · Primary</span
                                            ></Badge
                                        >
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <Badge
                                        :class="
                                            member.isActive
                                                ? 'bg-emerald-100 text-emerald-800 hover:bg-emerald-100'
                                                : ''
                                        "
                                        :variant="
                                            member.isActive
                                                ? 'secondary'
                                                : 'destructive'
                                        "
                                        >{{
                                            member.isActive
                                                ? 'Active'
                                                : 'Inactive'
                                        }}</Badge
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>
    </main>
</template>
