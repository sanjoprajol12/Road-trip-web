-- Milestone 2: verification review workflow, reviewer privacy, audit access for reviewers,
-- role management safety.

-- ---------------------------------------------------------------- review rules
-- Negative decisions must say why, in a new note (an old approval note does not count). The note
-- is shown to the rider and kept in the audit trail.
create or replace function public.guard_verification_review() returns trigger
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
  if new.status <> old.status and new.status in ('rejected', 'suspended', 'revoked')
     and (new.review_note is null or char_length(trim(new.review_note)) < 10
          or new.review_note is not distinct from old.review_note) then
    raise exception 'Give a reason of at least 10 characters when you % a verification',
      case new.status when 'rejected' then 'reject' when 'suspended' then 'suspend' else 'revoke' end
      using errcode = 'check_violation';
  end if;
  new.reviewed_by := (select auth.uid());
  new.reviewed_at := now();
  return new;
end $$;

-- Riders see their decision and note, not which staff member made it.
revoke select on public.user_verifications from authenticated;
grant select (id, user_id, kind, scope, status, statement, review_note, reviewed_at, created_at, updated_at)
  on public.user_verifications to authenticated;

-- ---------------------------------------------------------------- audit access
-- Reviewers need the history of the claims they decide; everything else stays admin-only.
create policy audit_logs_staff_verification_read on public.audit_logs for select to authenticated
  using (table_name = 'user_verifications' and public.is_staff());

-- ---------------------------------------------------------------- roles safety
-- Removing the last administrator would lock everyone out of role management.
-- Deleting the account itself (profile already gone, cascade) is allowed.
create function public.keep_one_admin() returns trigger
language plpgsql security definer set search_path = '' as $$
begin
  if old.role = 'admin'
     and exists (select 1 from public.profiles where id = old.user_id)
     and not exists (select 1 from public.user_roles where role = 'admin' and user_id <> old.user_id) then
    raise exception 'At least one administrator must remain' using errcode = 'check_violation';
  end if;
  return old;
end $$;
create trigger user_roles_keep_admin before delete on public.user_roles
  for each row execute function public.keep_one_admin();
revoke execute on function public.keep_one_admin() from public, anon, authenticated;

-- ---------------------------------------------------------------- user lookup for admins
-- Emails live in auth.users, which clients cannot read. Admins get a narrow, logged-in-only search.
create function public.admin_find_users(q text default '')
returns table (id uuid, email text, display_name text, username text, roles public.staff_role[], joined_at timestamptz)
language plpgsql stable security definer set search_path = '' as $$
declare
  pattern text := '%' || replace(replace(replace(coalesce(trim(q), ''), '\', '\\'), '%', '\%'), '_', '\_') || '%';
begin
  if not public.is_admin() then
    raise exception 'Administrators only' using errcode = 'insufficient_privilege';
  end if;
  return query
    select u.id, u.email::text, p.display_name, p.username,
           coalesce(array_agg(r.role order by r.role) filter (where r.role is not null), '{}'),
           u.created_at
    from auth.users u
    join public.profiles p on p.id = u.id
    left join public.user_roles r on r.user_id = u.id
    where u.email ilike pattern or p.display_name ilike pattern or coalesce(p.username, '') ilike pattern
    group by u.id, u.email, p.display_name, p.username, u.created_at
    order by u.created_at desc
    limit 50;
end $$;
revoke execute on function public.admin_find_users(text) from public, anon;
grant execute on function public.admin_find_users(text) to authenticated;
