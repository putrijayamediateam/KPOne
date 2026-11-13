/**
 * Version 2 Daily summary: the shapes the server sends and the plain-words helpers the page and the report block share.
 * A comparison is words only ("Up from 131"), never a colour; nothing is shown when both days are 0.
 */
export type Stamp = { slot: '0700' | '1400'; builtAt: string | null };
export type ClinicFigures = {
    registered: number;
    served: number;
    averageWaitMinutes: number;
    averageConsultationMinutes: number;
};
export type MoneyFigures = {
    netSalesSen: number;
    totalReceivedSen: number;
    cashNetSen: number;
    nonCashNetSen: number;
    panelApprovedSen: number;
    payLaterDueSen: number;
};
export type Lina = Stamp & {
    date: string;
    kind: 'whole_day' | 'so_far';
    comparedWith: string | null;
    clinic: { now: ClinicFigures; before: ClinicFigures | null } | null;
    doctors:
        | {
              doctorId: number;
              doctor: string;
              seen: number;
              averageConsultationMinutes: number;
              seenBefore: number | null;
          }[]
        | null;
    money: { now: MoneyFigures; before: MoneyFigures | null } | null;
    panel: {
        unclaimedInvoices: number;
        unclaimedSen: number;
        overdueUnclaimedInvoices: number;
        overdueUnclaimedSen: number;
        acceptedUnpaidClaims: number;
        acceptedOutstandingSen: number;
    } | null;
    stock: { lowStock: number; expiring: number; expired: number } | null;
};

export const ringgit = (sen: number): string =>
    `RM ${(sen / 100).toLocaleString('en-MY', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;

/** "1 item", "7 items". */
export const plural = (count: number, one: string, many = `${one}s`): string =>
    `${count} ${count === 1 ? one : many}`;

export const compare = (
    now: number,
    before: number | null | undefined,
    format: (value: number) => string = String,
): string | undefined => {
    if (
        before === null ||
        before === undefined ||
        (now === 0 && before === 0)
    ) {
        return undefined;
    }

    if (now > before) {
        return `Up from ${format(before)}`;
    }

    return now < before
        ? `Down from ${format(before)}`
        : `Same as ${format(before)}`;
};

export type StatItem = { label: string; value: string; detail?: string };

export const clinicStats = (lina: Lina): StatItem[] => {
    const now = lina.clinic!.now;
    const before = lina.clinic!.before;
    const minutes = (value: number) => `${value} min`;

    return [
        {
            label: 'Registered',
            value: String(now.registered),
            detail: compare(now.registered, before?.registered),
        },
        {
            label: 'Seen by a doctor',
            value: String(now.served),
            detail: compare(now.served, before?.served),
        },
        {
            label: 'Average wait',
            value: minutes(now.averageWaitMinutes),
            detail: compare(
                now.averageWaitMinutes,
                before?.averageWaitMinutes,
                minutes,
            ),
        },
        {
            label: 'Average consultation',
            value: minutes(now.averageConsultationMinutes),
            detail: compare(
                now.averageConsultationMinutes,
                before?.averageConsultationMinutes,
                minutes,
            ),
        },
    ];
};

export const moneyStats = (lina: Lina): StatItem[] => {
    const now = lina.money!.now;
    const before = lina.money!.before;
    const row = (
        label: string,
        key: keyof MoneyFigures,
        note?: string,
    ): StatItem => ({
        label,
        value: ringgit(now[key]),
        detail:
            [compare(now[key], before?.[key], ringgit), note]
                .filter(Boolean)
                .join('. ') || undefined,
    });

    return [
        row('Net invoice sales', 'netSalesSen'),
        row('Total received', 'totalReceivedSen', 'Cash and Non-cash'),
        row('Cash received', 'cashNetSen', 'After reversals'),
        row('Non-cash received', 'nonCashNetSen', 'After reversals'),
        row('Panel approved', 'panelApprovedSen'),
        row('Pay later due', 'payLaterDueSen'),
    ];
};

export const doctorLine = (
    doctor: NonNullable<Lina['doctors']>[number],
): string =>
    [
        doctor.seen > 0
            ? `${doctor.seen} seen, about ${doctor.averageConsultationMinutes} min each`
            : 'None seen',
        compare(doctor.seen, doctor.seenBefore),
    ]
        .filter(Boolean)
        .join(' · ');

// Units follow what the server counts: low stock is one row per item at a location ("by location"); expiring and expired are batches.
export const panelItems = (panel: NonNullable<Lina['panel']>) => [
    {
        label: 'Invoices not claimed yet',
        value: `${plural(panel.unclaimedInvoices, 'invoice')} (${ringgit(panel.unclaimedSen)}), ${panel.overdueUnclaimedInvoices} overdue (${ringgit(panel.overdueUnclaimedSen)})`,
    },
    {
        label: 'Accepted claims waiting for payment',
        value: `${plural(panel.acceptedUnpaidClaims, 'claim')} (${ringgit(panel.acceptedOutstandingSen)})`,
    },
];
export const stockItems = (stock: NonNullable<Lina['stock']>) => [
    {
        label: 'Low stock',
        value: `${plural(stock.lowStock, 'item')}, by location`,
    },
    {
        label: 'Expiring within 90 days',
        value: plural(stock.expiring, 'batch', 'batches'),
    },
    {
        label: 'Expired on hand',
        value: plural(stock.expired, 'batch', 'batches'),
    },
];
