-- Destinations: staff-curated editorial records. Rider contributions are a separate
-- table (later milestone) with their own moderation statuses.

create type public.publish_status as enum ('draft', 'published', 'archived');

create table public.destinations (
  id uuid primary key default gen_random_uuid(),
  slug text not null unique check (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
  name text not null check (char_length(trim(name)) between 2 and 80),
  province text check (province in ('Koshi', 'Madhesh', 'Bagmati', 'Gandaki', 'Lumbini', 'Karnali', 'Sudurpashchim')),
  district text check (char_length(district) <= 60),
  summary text check (char_length(summary) <= 400),
  overview text check (char_length(overview) <= 20000),
  elevation_m integer check (elevation_m between 0 and 9000),
  photo_path text,
  photo_alt text,
  photo_credit text,
  status public.publish_status not null default 'draft',
  -- When staff last confirmed this record against a source. Null = never confirmed.
  last_confirmed_on date,
  -- Development seed rows are labelled in the UI and must be replaced before launch.
  is_dev_seed boolean not null default false,
  created_by uuid references public.profiles (id) on delete set null default auth.uid(),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  deleted_at timestamptz,
  check (photo_path is null or (photo_alt is not null and photo_credit is not null))
);
create index destinations_public on public.destinations (name) where status = 'published' and deleted_at is null;
create trigger destinations_updated_at before update on public.destinations
  for each row execute function public.set_updated_at();
create trigger destinations_audit after insert or update or delete on public.destinations
  for each row execute function public.write_audit_log();

alter table public.destinations enable row level security;
revoke all on public.destinations from anon, authenticated;
grant select on public.destinations to anon, authenticated;
grant insert, update on public.destinations to authenticated;

create policy destinations_read on public.destinations for select
  using ((status = 'published' and deleted_at is null) or public.is_staff());
create policy destinations_staff_insert on public.destinations for insert to authenticated
  with check (public.is_staff());
-- Soft delete = set deleted_at; hard delete is not granted to anyone but the service role.
create policy destinations_staff_update on public.destinations for update to authenticated
  using (public.is_staff()) with check (public.is_staff());
