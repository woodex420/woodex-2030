-- Close Supabase's Data API on the shop's database (Supabase project "woodex-2030").
--
-- FOUND on 17 November 2026, reading the database before catalogue migration 019 was applied:
--   · none of the 72 tables of the public schema had row-level security, and there was no policy;
--   · Supabase's two API roles, "anon" and "authenticated", held every privilege on every one of them —
--     users, sessions, password_resets, leads, orders, invoices, payments, audit_logs included.
-- Supabase serves the public schema over HTTPS to anyone who holds the project's publishable ("anon") key. That key
-- is meant to be public; it is not in this repository and the shop does not use it — but nothing else stood
-- between the holder of that key and every row of the database, to read and to write.
--
-- The application does not use that API. The Node server connects over DATABASE_URL as the owner of the tables
-- ("postgres"); neither the shop nor the dashboard loads a Supabase client. So the two API roles need nothing.
--
-- WHAT THIS DOES: takes every privilege on the tables and sequences of the public schema from the two API roles,
-- and stops objects created later from being granted to them. It changes nothing for the owner of the tables,
-- and so nothing for the shop. The secret ("service_role") key is left as it is: it is a secret.
--
-- APPLIED to the live database on 17 November 2026 with the owner's word (decision log DL-022, DL-024).
-- Row-level security is the second lock and is not part of this file: it belongs in a numbered migration with
-- its test (master list, line T-163).
--
-- TO UNDO (only if a Supabase client is ever introduced, and then with policies instead):
--   GRANT ALL ON ALL TABLES IN SCHEMA public TO anon, authenticated;
--   GRANT ALL ON ALL SEQUENCES IN SCHEMA public TO anon, authenticated;
--   ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO anon, authenticated;
--   ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO anon, authenticated;

BEGIN;

REVOKE ALL ON ALL TABLES IN SCHEMA public FROM anon, authenticated;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM anon, authenticated;
ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON TABLES FROM anon, authenticated;
ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON SEQUENCES FROM anon, authenticated;

COMMIT;

-- TO CHECK (expect 0 and 0):
--   SELECT count(*) FILTER (WHERE has_table_privilege('anon', c.oid, 'select, insert, update, delete')) AS anon,
--          count(*) FILTER (WHERE has_table_privilege('authenticated', c.oid, 'select, insert, update, delete')) AS authenticated
--     FROM pg_class c WHERE c.relnamespace = 'public'::regnamespace AND c.relkind = 'r';
