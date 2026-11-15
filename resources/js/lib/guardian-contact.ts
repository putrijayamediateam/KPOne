export type GuardianInput = {
    relationship: string;
    full_name: string;
    phone: string;
    phone_country: string;
    email: string | null;
    lock_version: number;
    retry_key: string;
};
export function knownMinor(
    dob: unknown,
    today = new Date().toISOString().slice(0, 10),
): boolean {
    if (typeof dob !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(dob)) {
        return false;
    }

    const date = new Date(`${dob}T00:00:00Z`);

    if (
        Number.isNaN(date.getTime()) ||
        date.toISOString().slice(0, 10) !== dob ||
        dob > today
    ) {
        return false;
    }

    return (
        Number(today.slice(0, 4)) -
            Number(dob.slice(0, 4)) -
            (today.slice(5) < dob.slice(5) ? 1 : 0) <
        18
    );
}
export const blankGuardian = (): GuardianInput => ({
    relationship: '',
    full_name: '',
    phone: '',
    phone_country: 'MY',
    email: '',
    lock_version: 0,
    retry_key: crypto.randomUUID(),
});
