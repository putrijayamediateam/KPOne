import { formatRoleLabel } from '@/lib/displayLabels';

/**
 * Plain-words wording of a staff audit entry for the V2 staff page ("CA Supervisor role added by Demo Director").
 * It only uses what the page already receives (the event name, the role names and the actor); it never adds a staff name
 * or a branch name the payload does not carry. Classic keeps its own wording.
 */
const WORDS: Record<string, string> = {
    'staff.created': 'Staff account created',
    'staff.activated': 'Account activated',
    'staff.deactivated': 'Account deactivated',
    'staff.profile.updated': 'Profile updated',
    'staff.identity.updated': 'Sign-in details updated',
    'staff.department.changed': 'Department changed',
    'staff.director_bootstrapped': 'First Director account created',
    'staff.branch_assignment.created': 'Branch assignment added',
    'staff.branch_assignment.updated': 'Branch assignment changed',
    'staff.branch_assignment.ended': 'Branch assignment ended',
    'staff.branch_assignment.primary.promoted':
        'A branch was made the primary branch',
    'staff.branch_assignment.primary.demoted':
        'A branch stopped being the primary branch',
    'access.permission.attached': 'Permissions added',
    'access.permission.detached': 'Permissions removed',
};

const sentence = (event: string): string => {
    const text = event.replaceAll('_', ' ').replaceAll('.', ' ');

    return text.charAt(0).toUpperCase() + text.slice(1);
};

export function describeStaffEvent(
    event: string,
    roleNames: string[],
    actor: string | null,
): string {
    const roles = roleNames.map(formatRoleLabel).join(', ');
    let what: string;

    if (event === 'access.role.attached') {
        what = roles ? `${roles} role added` : 'Role added';
    } else if (event === 'access.role.detached') {
        what = roles ? `${roles} role removed` : 'Role removed';
    } else {
        what = WORDS[event] ?? sentence(event);
    }

    return `${what} by ${actor ?? 'the system'}`;
}
