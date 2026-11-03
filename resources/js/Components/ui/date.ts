/** Format stored timestamps consistently without changing their underlying values. */
function parseUtc(value: string): Date | null {
  const trimmed = value.trim();
  if (!trimmed) return null;
  // SQLite timestamps without an offset are stored as UTC by the application.
  const iso = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?$/.test(trimmed)
    ? `${trimmed.replace(' ', 'T')}Z`
    : trimmed;
  const parsed = new Date(iso);
  return Number.isNaN(parsed.getTime()) ? null : parsed;
}

export function formatDate(value: string | null | undefined): string {
  if (!value) return '—';
  const date = parseUtc(value);
  return date ? new Intl.DateTimeFormat('en-GB', {
    day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC',
  }).format(date) : value;
}

export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—';
  const date = parseUtc(value);
  if (!date) return value;
  const time = new Intl.DateTimeFormat('en-GB', {
    hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone: 'UTC',
  }).format(date);
  return `${formatDate(value)}, ${time} UTC`;
}
