-- ============================================================================
-- WOODEX — data request. Run in Supabase SQL Editor, paste the results back.
--
--   SQL Editor link:  https://supabase.com/dashboard/project/<REF>/sql/new
--   (replace <REF> with your project reference — see SUPABASE-SETUP.md)
--
-- Safe: read-only. No writes, no deletes, and no customer PII — no names,
-- emails or phone numbers are selected anywhere in this file.
--
-- Every section is independent. If one errors (for example a column that exists
-- in the repo's migrations but not in your live database — which is itself a
-- useful finding), skip it, keep going, and tell me which one failed.
-- ============================================================================

-- ── 1. Which tables actually hold data (the single most useful result) ──────
-- Tells me which of the 45 modules are live vs empty, which decides build order.
select table_name, row_count
from (
  select
    c.relname as table_name,
    (xpath('/row/c/text()',
      query_to_xml(format('select count(*) as c from public.%I', c.relname), false, true, '')
    ))[1]::text::int as row_count
  from pg_class c
  join pg_namespace n on n.oid = c.relnamespace
  where n.nspname = 'public' and c.relkind = 'r'
) counts
order by row_count desc, table_name;


-- ── 2. Schema drift: which migrations have actually been applied ────────────
-- If a migration exists in the repo but not here, the deployed DB is behind.
select version, name, inserted_at
from supabase_migrations.schema_migrations
order by version;


-- ── 3. RLS status per table (is the anon key safe?) ─────────────────────────
select tablename, rowsecurity as rls_enabled
from pg_tables
where schemaname = 'public'
order by rls_enabled asc, tablename;

-- 3b. Tables with RLS enabled but NO policy = silently returns nothing.
select t.tablename, count(p.policyname) as policy_count
from pg_tables t
left join pg_policies p on p.tablename = t.tablename and p.schemaname = t.schemaname
where t.schemaname = 'public'
group by t.tablename
having count(p.policyname) = 0
order by t.tablename;


-- ── 4. Storage buckets (product images, quotations, media) ──────────────────
select id, name, public, file_size_limit, created_at
from storage.buckets
order by name;


-- ── 5. Actual columns of the tables the dashboard reads most ────────────────
-- Lets me stop guessing and fix the row types against reality.
select table_name, column_name, data_type, is_nullable, column_default
from information_schema.columns
where table_schema = 'public'
  and table_name in (
    'products','customers','quotations','quotation_items','orders','order_items',
    'inventory','stock_movements','deliveries','delivery_zones','returns',
    'whatsapp_conversations','whatsapp_messages','analytics_daily',
    'profiles','user_permissions','user_activity_log','categories','media_assets'
  )
order by table_name, ordinal_position;


-- ── 6. Users and roles (what the RBAC phase has to work with) ───────────────
-- Emails omitted on purpose — I only need the shape of the role data.
select
  coalesce(role, '(null)') as role,
  count(*) as users
from public.profiles
group by role
order by users desc;

-- 6b. Is user_permissions populated at all? (skip if the table doesn't exist yet)
select
  (select count(*) from public.user_permissions) as permission_rows,
  (select count(distinct user_id) from public.user_permissions) as users_with_permissions,
  (select count(distinct module) from public.user_permissions) as distinct_modules;


-- ── 7. Date range of real activity (so charts and "this month" are honest) ──
select
  (select min(created_at)::date from public.orders)              as first_order,
  (select max(created_at)::date from public.orders)              as last_order,
  (select min(created_at)::date from public.quotations)          as first_quotation,
  (select max(created_at)::date from public.quotations)          as last_quotation,
  (select min(date)          from public.analytics_daily)        as analytics_from,
  (select max(date)          from public.analytics_daily)        as analytics_to;


-- ── 8. Real row shapes, with PII stripped ───────────────────────────────────
-- Two example rows per key table so I can fix row types against reality rather
-- than against the repo's migrations. Customer names/emails/phones are replaced.
select 'products' as src, to_jsonb(t) - 'description' as sample
from (select * from public.products limit 2) t
union all
select 'customers', to_jsonb(t) - 'full_name' - 'company_name' - 'email' - 'phone' - 'whatsapp_number' - 'address' - 'notes'
from (select * from public.customers limit 2) t
union all
select 'quotations', to_jsonb(t) - 'notes'
from (select * from public.quotations limit 2) t
union all
select 'orders', to_jsonb(t)
from (select * from public.orders limit 2) t
union all
select 'inventory', to_jsonb(t)
from (select * from public.inventory limit 2) t
union all
select 'deliveries', to_jsonb(t) - 'delivery_address' - 'recipient_name' - 'recipient_phone'
from (select * from public.deliveries limit 2) t
union all
select 'returns', to_jsonb(t) - 'customer_notes' - 'admin_notes'
from (select * from public.returns limit 2) t
union all
select 'whatsapp_messages', to_jsonb(t) - 'content'
from (select * from public.whatsapp_messages order by created_at desc limit 2) t
union all
select 'analytics_daily', to_jsonb(t)
from (select * from public.analytics_daily order by date desc limit 2) t;
