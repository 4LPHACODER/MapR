const link = (to, label, icon, badge) => ({ to, label, icon, badge });
const home = link('/', 'Dashboard', 'dashboard');
const profile = link('/profile', 'Profile', 'user');
const drafts = link('/reports/drafts', 'Draft Reports', 'reports', 'drafts');
const returned = link('/reports/returned', 'Returned for Correction', 'undo', 'returned');
const submitted = link('/reports/submitted', 'Submitted Reports', 'send', 'submitted');
const barangay = link('/reports/barangay', 'Barangay Reports', 'reports', 'barangay_reports');
const field = link('/reports/field', 'Field Assessment Reports', 'reports', 'field_assessments');

export function workspaceNavigation(workspace, roleCode = '') {
    switch (workspace) {
        case 'resident': return {
            label: 'Resident',
            items: [home, link('/map', 'Map', 'map'), link('/report', 'Report Incident', 'camera'), link('/reports', 'My Reports', 'reports'), { ...profile, label: 'My Profile' }],
            mobile: ['/', '/map', '/reports', '/profile'],
        };
        case 'bdrrm': return {
            label: 'BDRRM', badgeUrl: '/bdrrm/api/navigation',
            items: [home, link('/map', 'Barangay Map', 'map'), link('/submissions', 'Resident Submissions', 'users', 'submissions'),
                ...(['barangay_secretary', 'barangay_captain'].includes(roleCode) ? [link('/residents', 'Residents Records', 'users-round')] : []),
                link('/reports/new', 'Create Barangay Report', 'camera'), drafts, returned, submitted, profile],
            mobile: ['/', '/map', '/reports/new', '/reports/drafts'],
        };
        case 'assessor': return {
            label: 'Assessor', badgeUrl: '/rdana/api/navigation',
            items: [home, link('/map', 'Assessment Map', 'map'), link('/buildings', 'Building Records', 'building-2'), barangay, field, { ...drafts, label: 'Drafts' }, returned, submitted, profile],
            mobile: ['/', '/map', '/reports/barangay', '/reports/field'],
        };
        case 'cdrrmo': return {
            label: 'CDRRMO', badgeUrl: '/cdrrmo/api/nav-badges',
            items: [home, link('/map', 'Assessment Map', 'map'), link('/incidents', 'Disaster Incidents', 'disaster'), link('/residents', 'Residents', 'users'),
                barangay, field, link('/reports/consolidated', 'Consolidated Reports', 'reports', 'consolidated_reports'),
                { ...link({ path: '/map', query: { mode: 'estimate' } }, 'Create Impact Report', 'impact'), key: 'impact' },
                link('/users', 'User Management', 'user-cog'), link('/activity', 'Activity Log', 'history'), profile],
            mobile: ['/', '/map', '/reports/barangay', '/reports/field'],
        };
        default: throw new Error(`Unknown workspace: ${workspace}`);
    }
}

export function isNavigationActive(item, route) {
    const path = typeof item.to === 'string' ? item.to : item.to.path;
    if (item.key === 'impact') return route.path === path && route.query.mode === 'estimate';
    if (path === '/map' && route.query.mode === 'estimate') return false;
    return path === '/' ? route.path === '/' : route.path === path || route.path.startsWith(`${path}/`);
}
