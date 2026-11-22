// Response shapes of the Laravel API (app/Http/Resources). Keep in step with those classes.

export interface VerifiedClaim {
  kind: VerificationKind
  scope: string
}

export interface Me {
  id: string
  display_name: string
  username: string | null
  email: string
  email_verified: boolean
  bio: string | null
  home_city: string | null
  /** Staff role slugs (built-in or custom); empty for riders. */
  verified: VerifiedClaim[]
  suspended_until: string | null
  contribution_suspension?: { until: string; reason: string; appealed_at: string | null; appeal_decision: 'upheld' | 'lifted' | null } | null
  /** Set while a requested account deletion waits out its 30-day cooling-off. */
  deletion_scheduled_for: string | null
  created_at: string
}

/** A rider as others see them. id and joined are null for a "Former rider" (an account that was deleted). */
export interface PublicUser {
  id: string | null
  display_name: string
  username: string | null
  home_city?: string | null
  bio?: string | null
  joined: string | null
  verified: VerifiedClaim[]
}

export type PublishStatus = 'draft' | 'published' | 'archived'

export interface Destination {
  id: string
  slug: string
  name: string
  province: string | null
  district: string | null
  summary: string | null
  overview: string | null
  elevation_m: number | null
  latitude: number | null
  longitude: number | null
  photo_path: string | null
  photo_alt: string | null
  photo_credit: string | null
  status: PublishStatus
  last_confirmed_on: string | null
  is_dev_seed: boolean
  updated_at: string
  sources?: Source[]
}

export interface ServicePoint {
  id: string
  kind: 'fuel' | 'mechanic' | 'medical' | 'lodging' | 'food' | 'atm' | 'connectivity'
  name: string
  notes: string | null
  position: [number, number] | null
  last_confirmed_on: string | null
}

export interface Segment {
  id: string
  position: number
  start_name: string
  end_name: string
  start: [number, number] | null
  end: [number, number] | null
  distance_km: number | null
  surface: 'paved' | 'mixed' | 'gravel' | 'dirt' | 'unknown'
  notes: string | null
  services: ServicePoint[]
  /** Imported track [[lat, lng], ...], or null: only this is drawn as a road line. */
  path: [number, number][] | null
  /** Staff estimate from the written-up route, per stretch; null when not recorded. */
  estimated_hours: number | null
}

export interface RideRoute {
  id: string
  slug: string
  name: string
  summary: string | null
  start: { id: string; slug: string; name: string } | null
  end: { id: string; slug: string; name: string } | null
  distance_km: number | null
  estimated_hours: number | null
  status: PublishStatus
  last_confirmed_on: string | null
  is_dev_seed: boolean
  segments?: Segment[]
  sources?: Source[]
}

export type ContributionKind = 'trip' | 'road_report' | 'accommodation' | 'fuel' | 'mechanic' | 'recommendation' | 'unexpected' | 'observation'
export type ContributionStatus = 'pending_review' | 'published' | 'community_corroborated' | 'officially_sourced' | 'outdated' | 'rejected' | 'flagged'

export interface Contribution {
  id: string
  kind: ContributionKind
  title: string
  body: string
  observed_on: string
  submitted_at: string
  status: ContributionStatus
  details: Partial<{
    motorcycle: string
    engine_cc: number
    duration_days: number
    expenses_npr: number
    surface: string
    problems: string
    lessons: string
    wish_known: string
    places: string
  }>
  source_url: string | null
  moderation_note?: string | null
  author?: PublicUser
  place?: { slug: string; name: string } | null
  destination?: { slug: string; name: string } | null
  route?: { slug: string; name: string } | null
  segment?: { id: string; start_name: string; end_name: string } | null
  media?: { id: string; url: string; width: number; height: number; alt: string }[]
  last_confirmed_on: string | null
  /** The author's dated addendum; public once a moderator has seen it. */
  author_correction: string | null
  author_corrected_at: string | null
  /** Only for the author: what a moderator asked them to correct. */
  correction_request?: string | null
  correction_pending_review?: boolean
  sources?: Source[]
}

export type EventStatus = 'draft' | 'published' | 'full' | 'postponed' | 'cancelled' | 'completed'
export type RegistrationStatus = 'invited' | 'pending' | 'approved' | 'declined' | 'waitlisted' | 'cancelled' | 'attended' | 'did_not_attend'

export interface RideEvent {
  id: string
  category: 'ride' | 'gathering'
  subtype: string
  title: string
  description: string
  starts_at: string
  ends_at: string
  meeting_point: string
  visibility: 'public' | 'private'
  join_method: 'instant' | 'approval'
  capacity: number | null
  waitlist_enabled: boolean
  status: EventStatus
  summary: string | null
  participant_count: number
  organizer: PublicUser
  route: { slug: string; name: string } | null
  my_registration: RegistrationStatus | null
  is_organizer: boolean
  updates?: { id: number; kind: string; body: string; created_at: string }[]
}

export interface Registration {
  id: string
  status: RegistrationStatus
  updated_at: string
  rider: { id: string; display_name: string; username: string | null }
}

export interface Trip {
  id: string
  title: string
  starts_on: string | null
  ends_on: string | null
  motorcycle: string | null
  notes: string | null
  my_role: 'owner' | 'editor' | 'viewer'
  member_count: number
  members?: { id: string; display_name: string; username: string | null; role: 'owner' | 'editor' | 'viewer' }[]
  /** Owner only: invitations waiting for an answer. */
  invitations?: { id: string; role: 'editor' | 'viewer'; expires_at: string; display_name: string; username: string | null; expired: boolean }[]
  stops?: {
    id: string; position: number; day: number | null; name: string; destination_id: string | null; route_id: string | null; service_point_id: string | null; place_id: string | null; overnight: boolean; note: string | null
    /** Linked records, only while they are published. */
    place: { slug: string; name: string; category: string; position: [number, number] | null } | null
    destination: { slug: string; name: string; position: [number, number] | null } | null
    service: { name: string; kind: string; position: [number, number] | null } | null
    /** Session 19: a whole route, one stretch, or one checkpoint (null for other stops). */
    route_segment_id: string | null
    route_part: 'route' | 'stretch' | 'checkpoint_start' | 'checkpoint_end' | null
    route: { slug: string; name: string; distance_km: number | null; estimated_hours: number | null; stretches: number; without_distance: number; without_time: number; without_track: number } | null
    stretch: { from: string; to: string; distance_km: number | null; estimated_hours: number | null; surface: Segment['surface']; has_track: boolean } | null
    checkpoint: { name: string; position: [number, number] | null } | null
    /** Recorded tracks of this route or stretch ([[lat, lng], ...] each); empty when none is recorded. */
    tracks: [number, number][][]
    linked_unavailable: boolean
  }[]
  /** All amounts NPR. A planned cost may have no amount yet; a spent one always has one. */
  expenses?: { id: string; label: string; category: string; amount_npr: number | null; paid_by: string | null; status: 'planned' | 'spent' }[]
  expense_total_npr?: number
  budget?: { planned_npr: number; spent_npr: number; planned_without_amount: number }
  checklist?: { id: string; label: string; section: 'motorcycle' | 'personal' | 'trip'; done: boolean; position: number }[]
  updated_at: string
}

export interface Guide {
  id: string
  slug: string
  title: string
  category: 'preparation' | 'mechanical' | 'medical' | 'road_safety' | 'weather' | 'emergency'
  body: string
  sources: string
  status: PublishStatus
  needs_expert_review: boolean
  expert_reviewed: boolean
  expert_reviewed_at: string | null
  updated_at: string
}

export type VerificationKind = 'identity' | 'trip_contributor' | 'expert_reviewer' | 'event_organizer'
export type VerificationStatus = 'pending' | 'approved' | 'rejected' | 'suspended' | 'revoked'

export interface OwnVerification {
  id: string
  kind: VerificationKind
  scope: string
  status: VerificationStatus
  statement: string
  review_note: string | null
  reviewed_at: string | null
  created_at: string
}

export interface AppNotification {
  id: string
  data: { kind: string; title?: string; body?: string; note?: string; status?: string; claim_kind?: string; change?: 'promoted' | 'approved' | 'declined' | 'removed' | 'published' | 'changes_requested' | 'rejected' | 'suspended' | 'lifted' | 'appeal_upheld'; link?: string; by?: string; subject?: 'event' | 'trip' }
  read_at: string | null
  created_at: string
}

export interface Source {
  id: string
  title: string
  publisher: string | null
  url: string | null
  accessed_on: string | null
}

export type ReportCategory = 'dangerous' | 'wrong' | 'outdated' | 'spam' | 'offensive' | 'other'

export interface Paginated<T> {
  data: T[]
  meta?: { current_page: number; last_page: number; total: number }
}
