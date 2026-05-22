/**
 * Sadə debounce — filter input-ları üçün.
 */
export function debounce(fn, wait = 250) {
    let timeout;
    return function (...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => fn.apply(this, args), wait);
    };
}
