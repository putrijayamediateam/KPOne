<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { retireAuthenticationHistoryForLogout } from '@/lib/inertia-auth-history-boundary';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

const handleLogout = () => {
    retireAuthenticationHistoryForLogout(router);
    router.flushAll();
};

defineOptions({
    layout: {
        title: 'Email verification',
        description:
            'Please verify your email address by clicking on the link we just emailed to you.',
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head title="Email verification" />

    <div
        v-if="status === 'verification-link-sent'"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        A new verification link has been sent to the email address you provided
        during registration.
    </div>

    <Form
        v-bind="send.form()"
        class="space-y-6 text-center"
        v-slot="{ processing }"
    >
        <Button :disabled="processing" variant="secondary">
            <Spinner v-if="processing" />
            Resend verification email
        </Button>

        <TextLink
            :href="logout()"
            as="button"
            class="mx-auto block text-sm"
            @start="handleLogout"
        >
            Log out
        </TextLink>
    </Form>
</template>
