// HR-4a staff file shapes, matching RecordsPageService.
export type Option = { value: string; label: string };

export type RecordOptions = {
    types: Option[];
    endReasons: Option[];
    editReasons: Option[];
    correctionKinds: Option[];
    correctionReasons: Option[];
    correctionRejectReasons: Option[];
    bankRejectReasons: Option[];
    departments: { id: number; name: string }[];
};

export type WorkerHeader = {
    id: string;
    name: string;
    kind: 'staff' | 'locum';
    role: string;
    type: string | null;
    typeLabel: string | null;
    status: 'active' | 'probation' | 'ended' | 'none';
    statusLabel: string;
    probationEndsOn: string | null;
    hasLocumPhone: boolean;
};

export type Address = {
    line1: string;
    line2: string;
    city: string;
    state: string;
    postcode: string;
    country: string;
};

export type EmergencyContact = {
    name: string;
    relationship: string;
    phone: string;
};

export const statusTone = (status: WorkerHeader['status']) =>
    status === 'probation'
        ? 'warning'
        : status === 'active'
          ? 'success'
          : 'neutral';

const months = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];

/** "8 Mar 2027" from a Y-m-d date, without time zone drift. */
export const dateLabel = (value: string | null | undefined): string => {
    if (!value) {
        return '';
    }

    const [y, m, d] = value.slice(0, 10).split('-').map(Number);

    return `${d} ${months[m - 1]} ${y}`;
};

export const addressLine = (a: Address | null): string =>
    a
        ? [a.line1, a.line2, a.city, a.state, a.postcode, a.country]
              .filter((p) => p)
              .join(', ')
        : '';

export const csrf = (): string =>
    decodeURIComponent(
        document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );
