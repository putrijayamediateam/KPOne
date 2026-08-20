const roleLabels: Record<string, string> = {
    ca: 'CA',
    ca_supervisor: 'CA Supervisor',
    hr_manager: 'HR Manager',
};

export const formatRoleLabel = (role: string): string =>
    roleLabels[role] ??
    role
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

export const formatAuditEventLabel = (
    event: string,
    roleNames: string[] = [],
): string => {
    const eventLabel = event
        .split('.')
        .map(
            (segment) =>
                segment.charAt(0).toUpperCase() +
                segment.slice(1).replaceAll('_', ' '),
        )
        .join(' › ');
    const roleSummary = roleNames.map(formatRoleLabel).join(', ');

    return roleSummary ? `${eventLabel} · ${roleSummary}` : eventLabel;
};
