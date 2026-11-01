export function formatWhen(value) {
    if (!value) return '';
    const parsed = new Date(String(value).includes('T') ? value : String(value).replace(' ', 'T'));
    if (Number.isNaN(parsed.getTime())) return String(value);
    return parsed.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

export function statusClass(label) {
    if (label === 'Needs Correction') return 'is-correction';
    if (label === 'Finalized') return 'is-final';
    if (label === 'Draft') return 'is-draft';
    if (label === 'Pending Assessor Review') return 'is-assessor';
    if (label === 'Pending CDRRMO Review') return 'is-cdrrmo';
    return 'is-review';
}
