const euro = new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' });
export const eur = (cents) => euro.format((cents ?? 0) / 100);
export const num = (n) => new Intl.NumberFormat('it-IT').format(n ?? 0);
export const dateTime = (iso) => (iso ? new Date(iso).toLocaleString('it-IT', { dateStyle: 'short', timeStyle: 'medium' }) : '—');
export const seconds = (ms) => (ms == null ? '—' : `${(ms / 1000).toLocaleString('it-IT', { maximumFractionDigits: 1 })} s`);

export function xsrfHeader() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {};
}
