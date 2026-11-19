// Dates are always shown in full ("4 Nov 2026"), never as 11/04, so observation dates are unambiguous.
const dayFormat = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Asia/Kathmandu' })

export function formatDay(value: string | Date | null | undefined): string {
  if (!value) return ''
  // A bare YYYY-MM-DD is a calendar date: pin it to Nepal noon so no timezone can shift the day.
  const date = typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T12:00:00+05:45`) : new Date(value)
  return Number.isNaN(date.getTime()) ? '' : dayFormat.format(date)
}

/** "today", "3 days ago", "5 months ago": how old an observation is, for freshness labels. */
export function ageInWords(value: string | Date, now = new Date()): string {
  const days = Math.floor((now.getTime() - new Date(value).getTime()) / 86_400_000)
  if (days < 1) return 'today'
  if (days < 31) return days === 1 ? '1 day ago' : `${days} days ago`
  const months = Math.floor(days / 30.44)
  if (months < 12) return months === 1 ? '1 month ago' : `${months} months ago`
  const years = Math.floor(months / 12)
  return years === 1 ? '1 year ago' : `${years} years ago`
}

const timeFormat = new Intl.DateTimeFormat('en-GB', { hour: 'numeric', minute: '2-digit', hour12: true, timeZone: 'Asia/Kathmandu' })

/** "4 Nov 2026, 3:05 pm" in Nepal time, for audit trails and decisions. */
export function formatDayTime(value: string | Date | null | undefined): string {
  if (!value) return ''
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? '' : `${dayFormat.format(date)}, ${timeFormat.format(date)}`
}
