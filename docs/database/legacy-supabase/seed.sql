-- DEVELOPMENT SEED DATA ONLY. Never run against a production project.
-- Names, provinces and districts are factual; everything else is deliberately left empty
-- so nothing unverified (prices, road conditions, services) reaches the UI.
-- Rows carry is_dev_seed = true and the UI labels them.

insert into public.destinations (slug, name, province, district, summary, photo_path, photo_alt, photo_credit, status, is_dev_seed, created_by)
values
  ('pokhara', 'Pokhara', 'Gandaki', 'Kaski',
   'Development seed record. Route, service and cost information has not been verified yet.',
   '/images/photos/pokhara-machhapuchhre', 'Machhapuchhre peak above Pokhara with a paraglider in the sky', 'Sagar Dani, CC0',
   'published', true, null),
  ('bandipur', 'Bandipur', 'Gandaki', 'Tanahun',
   'Development seed record. Route, service and cost information has not been verified yet.',
   '/images/photos/bandipur-bazaar', 'The stone-paved main street of Bandipur bazaar lined with brick houses', 'Maesi64, CC0',
   'published', true, null),
  ('mustang', 'Mustang', 'Gandaki', 'Mustang',
   'Development seed record. Route, service and cost information has not been verified yet.',
   null, null, null,
   'published', true, null),
  ('rara', 'Rara', 'Karnali', 'Mugu',
   'Development seed record. Route, service and cost information has not been verified yet.',
   '/images/photos/rara-lake', 'Rara Lake surrounded by forested hills', 'scvishnu7 and phuchche, CC0',
   'published', true, null)
on conflict (slug) do nothing;
