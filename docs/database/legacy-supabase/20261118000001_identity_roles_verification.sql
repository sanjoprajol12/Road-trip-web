-- My Ride Nepal: identity, staff roles, claim-specific verification, audit log.
-- Authorization lives here (RLS + grants + triggers), never only in the frontends.
-- Supabase grants ALL on new public tables to anon/authenticated by default, so every
-- table below revokes that first and grants back only what its policies need.

create type public.staff_role as enum ('moderator', 'admin');
create type public.verification_kind as enum ('identity', 'trip_contributor', 'expert_reviewer', 'event_organizer');
create type public.verification_status as enum ('pending', 'approved', 'rejected', 'suspended', 'revoked');

create function public.set_updated_at() returns trigger
language plpgsql set search_path = '' as $$
begin
  new.updated_at := now();
  return new;
end $$;

-- ---------------------------------------------------------------- profiles
create table public.profiles (
  id uuid primary key references auth.users (id) on delete cascade,
  display_name text not null check (char_length(trim(display_name)) between 1 and 60),
  username text check (username ~ '^[a-z0-9_]{3,30}$'),
  bio text check (char_length(bio) <= 500),
  home_city text check (char_length(home_city) <= 80),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  deleted_at timestamptz
);
create unique index profiles_username_key on public.profiles (username) where username is not null;
create trigger profiles_updated_at before update on public.profiles
  for each row execute function public.set_updated_at();

create function public.handle_new_user() returns trigger
language plpgsql security definer set search_path = '' as $$
begin
  insert into public.profiles (id, display_name)
  values (
    new.id,
    left(coalesce(nullif(trim(new.raw_user_meta_data ->> 'display_name'), ''), split_part(new.email, '@', 1), 'Rider'), 60)
  );
  return new;
end $$;
create trigger on_auth_user_created after insert on auth.users
  for each row execute function public.handle_new_user();

-- ---------------------------------------------------------------- staff roles
-- Riders have no row here; "registered rider" = any signed-in account.
create table public.user_roles (
  user_id uuid not null references public.profiles (id) on delete cascade,
  role public.staff_role not null,
  granted_by uuid references public.profiles (id) on delete set null default auth.uid(),
  granted_at timestamptz not null default now(),
  primary key (user_id, role)
);

create function public.has_role(r public.staff_role) returns boolean
language sql stable security definer set search_path = '' as $$
  select exists (select 1 from public.user_roles where user_id = (select auth.uid()) and role = r)
$$;

create function public.is_admin() returns boolean
language sql stable security definer set search_path = '' as $$
  select public.has_role('admin')
$$;

-- Admins can do everything moderators can.
create function public.is_staff() returns boolean
language sql stable security definer set search_path = '' as $$
  select exists (select 1 from public.user_roles where user_id = (select auth.uid()))
$$;

-- ---------------------------------------------------------------- verifications
-- One table for every claim-specific status. Approval of one kind never implies another.
-- Suspension or revocation takes effect immediately because every check reads this table live.
create table public.user_verifications (
  id uuid primary key default gen_random_uuid(),
  user_id uuid not null references public.profiles (id) on delete cascade,
  kind public.verification_kind not null,
  -- Expert reviewers are verified per domain (e.g. mechanical, medical, road_safety).
  scope text not null default '' check (scope ~ '^[a-z_]{0,40}$'),
  status public.verification_status not null default 'pending',
  statement text not null check (char_length(trim(statement)) between 20 and 2000),
  review_note text check (char_length(review_note) <= 2000),
  reviewed_by uuid references public.profiles (id) on delete set null,
  reviewed_at timestamptz,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  check ((kind = 'expert_reviewer') = (scope <> ''))
);
-- At most one live claim per kind/scope; rejected and revoked rows stay as history.
create unique index user_verifications_one_open
  on public.user_verifications (user_id, kind, scope)
  where status in ('pending', 'approved', 'suspended');
create index user_verifications_queue on public.user_verifications (status, created_at) where status = 'pending';
create trigger user_verifications_updated_at before update on public.user_verifications
  for each row execute function public.set_updated_at();

create function public.has_verification(k public.verification_kind, s text default '') returns boolean
language sql stable security definer set search_path = '' as $$
  select exists (
    select 1 from public.user_verifications
    where user_id = (select auth.uid()) and kind = k and scope = s and status = 'approved'
  )
$$;

-- Who may decide which claim. Organizer and expert status are admin decisions.
create function public.can_review_verification(k public.verification_kind) returns boolean
language sql stable security definer set search_path = '' as $$
  select case when k in ('event_organizer', 'expert_reviewer') then public.is_admin() else public.is_staff() end
$$;

create function public.guard_verification_review() returns trigger
language plpgsql set search_path = '' as $$
begin
  if new.user_id <> old.user_id or new.kind <> old.kind or new.scope <> old.scope or new.statement <> old.statement then
    raise exception 'Only the status and review note of a verification can change' using errcode = 'check_violation';
  end if;
  if new.user_id = (select auth.uid()) then
    raise exception 'You cannot review your own verification' using errcode = 'insufficient_privilege';
  end if;
  if new.status <> old.status and not (
       (old.status = 'pending'   and new.status in ('approved', 'rejected'))
    or (old.status = 'approved'  and new.status in ('suspended', 'revoked'))
    or (old.status = 'suspended' and new.status in ('approved', 'revoked'))
  ) then
    raise exception 'Verification cannot move from % to %', old.status, new.status using errcode = 'check_violation';
  end if;
  new.reviewed_by := (select auth.uid());
  new.reviewed_at := now();
  return new;
end $$;
create trigger user_verifications_review before update on public.user_verifications
  for each row execute function public.guard_verification_review();

-- ---------------------------------------------------------------- audit log
-- No foreign key on actor_id so the trail survives account deletion.
create table public.audit_logs (
  id bigint generated always as identity primary key,
  actor_id uuid,
  action text not null check (action in ('INSERT', 'UPDATE', 'DELETE')),
  table_name text not null,
  record_id text not null,
  old_data jsonb,
  new_data jsonb,
  created_at timestamptz not null default now()
);
create index audit_logs_record on public.audit_logs (table_name, record_id);
create index audit_logs_created on public.audit_logs (created_at desc);

create function public.write_audit_log() returns trigger
language plpgsql security definer set search_path = '' as $$
declare
  row_data jsonb := case tg_op when 'DELETE' then to_jsonb(old) else to_jsonb(new) end;
begin
  insert into public.audit_logs (actor_id, action, table_name, record_id, old_data, new_data)
  values (
    (select auth.uid()), tg_op, tg_table_name,
    coalesce(row_data ->> 'id', row_data ->> 'user_id'),
    case when tg_op <> 'INSERT' then to_jsonb(old) end,
    case when tg_op <> 'DELETE' then to_jsonb(new) end
  );
  return null;
end $$;

create trigger user_roles_audit after insert or update or delete on public.user_roles
  for each row execute function public.write_audit_log();
create trigger user_verifications_audit after insert or update or delete on public.user_verifications
  for each row execute function public.write_audit_log();

-- ---------------------------------------------------------------- grants + RLS
revoke execute on function public.handle_new_user(), public.write_audit_log(),
  public.guard_verification_review(), public.set_updated_at() from public, anon, authenticated;

alter table public.profiles enable row level security;
alter table public.user_roles enable row level security;
alter table public.user_verifications enable row level security;
alter table public.audit_logs enable row level security;

revoke all on public.profiles, public.user_roles, public.user_verifications, public.audit_logs from anon, authenticated;

-- profiles: public read of the public columns, owners edit their own editable columns.
grant select on public.profiles to anon, authenticated;
grant update (display_name, username, bio, home_city) on public.profiles to authenticated;
create policy profiles_read on public.profiles for select
  using (deleted_at is null or public.is_staff());
create policy profiles_update_own on public.profiles for update to authenticated
  using (id = (select auth.uid())) with check (id = (select auth.uid()));

-- user_roles: you can see your own roles; only admins grant or remove roles.
grant select, insert, delete on public.user_roles to authenticated;
create policy user_roles_read on public.user_roles for select to authenticated
  using (user_id = (select auth.uid()) or public.is_admin());
create policy user_roles_admin_insert on public.user_roles for insert to authenticated
  with check (public.is_admin());
create policy user_roles_admin_delete on public.user_roles for delete to authenticated
  using (public.is_admin());

-- user_verifications: riders apply for themselves (always pending), reviewers decide.
grant select on public.user_verifications to authenticated;
grant insert (user_id, kind, scope, statement) on public.user_verifications to authenticated;
grant update (status, review_note) on public.user_verifications to authenticated;
create policy verifications_read on public.user_verifications for select to authenticated
  using (user_id = (select auth.uid()) or public.is_staff());
create policy verifications_apply on public.user_verifications for insert to authenticated
  with check (user_id = (select auth.uid()) and status = 'pending');
create policy verifications_review on public.user_verifications for update to authenticated
  using (public.can_review_verification(kind)) with check (public.can_review_verification(kind));

-- audit_logs: admins read; nobody writes except the trigger.
grant select on public.audit_logs to authenticated;
create policy audit_logs_admin_read on public.audit_logs for select to authenticated
  using (public.is_admin());
