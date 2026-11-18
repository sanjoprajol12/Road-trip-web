import type { EventStatus, RegistrationStatus } from '@/types/api'

export const eventStatusLabels: Record<EventStatus, string> = {
  draft: 'Draft', published: 'Open', full: 'Full', postponed: 'Postponed', cancelled: 'Cancelled', completed: 'Completed',
}
export const registrationLabels: Record<RegistrationStatus, string> = {
  invited: 'Invited', pending: 'Request sent', approved: 'Going', declined: 'Declined', waitlisted: 'On the waitlist',
  cancelled: 'Cancelled', attended: 'Attended', did_not_attend: 'Did not attend',
}
const when = new Intl.DateTimeFormat('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true, timeZone: 'Asia/Kathmandu' })
export const eventTime = (iso: string) => when.format(new Date(iso))
