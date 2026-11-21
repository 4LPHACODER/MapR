const pad = (value) => String(value).padStart(2, '0');

function isValid(date) {
    return date instanceof Date && !Number.isNaN(date.getTime());
}

export function isoToDate(value) {
    if (!value) return null;
    const [year, month, day] = String(value).slice(0, 10).split('-').map(Number);
    // setFullYear keeps years 0-99 as written; new Date(y, m, d) would map them to 1900-1999.
    const date = new Date(2000, 0, 1);
    date.setFullYear(year, month - 1, day);
    // A native date input shows nothing for 2026-02-31. Do not roll it over to 3 March.
    if (!isValid(date) || date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) return null;
    return date;
}

export function dateToIso(date) {
    if (!isValid(date)) return '';
    return `${String(date.getFullYear()).padStart(4, '0')}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

export function localDateTimeToDate(value) {
    if (!value) return null;
    const date = new Date(String(value).slice(0, 16).replace(' ', 'T'));
    return isValid(date) ? date : null;
}

export function dateToLocalDateTime(date) {
    if (!isValid(date)) return '';
    return `${dateToIso(date)}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}
