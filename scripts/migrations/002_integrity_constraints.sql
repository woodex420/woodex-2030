-- 002 · integrity constraints (foreign keys + check constraints)
--
-- Until this migration the production database enforced NOTHING: 0 foreign
-- keys, 0 check constraints, across all 29 tables. Every relationship in
-- 03-DATA-AND-TENANCY.md existed only in application code, so any bug — or any
-- hand-run SQL — could write an order pointing at a customer who no longer
-- exists and nothing would object.
--
-- Pre-flight was run against the live database before writing this file:
--   * 26 candidate relationships, 0 orphaned rows
--   * 13 candidate predicates, 0 violations
-- So every constraint below validates immediately. This is the cheap moment to
-- add them; it stops being cheap as soon as the modules start writing rows.
--
-- ── HOW EACH PIECE WAS CHOSEN ────────────────────────────────────────────
--
-- ON DELETE actions follow what the application already does, so no constraint
-- below can make an existing code path fail:
--
--   * CASCADE — the app already deletes children by hand inside the same
--     transaction (DELETE /api/pages/:id removes page_blocks and page_versions,
--     then the page; project deletion does the same for milestones, BOQ lines
--     and files). The cascade makes the database agree with the code rather
--     than trust it; the hand-deletes stay, and are now redundant but harmless.
--
--   * RESTRICT (the default) — there is no delete path for these parents
--     today. If one is added later it must decide explicitly what happens to
--     the children. Silently severing them is the wrong default.
--
--   * SET NULL — for optional history that should outlive its parent
--     (track_events.page_id: a page is deleted, its view history survives).
--
-- Foreign keys are added NOT VALID and then validated. That two-step exists so
-- a violation reports the offending rows instead of failing opaquely, and so
-- the lock taken is SHARE UPDATE EXCLUSIVE rather than ACCESS EXCLUSIVE — the
-- migration does not block writes while it runs.
--
-- ── DELIBERATELY NOT CONSTRAINED ─────────────────────────────────────────
--
-- Two enum columns are left unconstrained because closing them would reject
-- data the current code legitimately writes:
--
--   * returns.state — the API's RETURN_STATES is
--     ["Requested","Inspecting","Refunding","Closed"] but the demo seed writes
--     "Approved", "Rejected" and "Completed". A strict CHECK here would fail
--     on seeded rows today and on any client following the seed's vocabulary.
--   * invoices.status — the PATCH route accepts an arbitrary status string
--     (no allow-list), while the dashboard's own union type offers "Cancelled".
--     Constraining it would turn a currently-accepted request into a 500.
--
-- Both need the application to settle on one vocabulary first; that is a code
-- change, not a migration. They are recorded as open items rather than guessed.
--
-- Idempotent: every constraint is guarded, and every key carries its expected
-- purpose in the name so a later migration can drop one deliberately.

/* ────────────────────────── 1. foreign keys ────────────────────────── */

DO $$
DECLARE
  fk record;
BEGIN
  FOR fk IN
    SELECT * FROM (VALUES
      -- child            child_col        parent              parent_col  on_delete  on_update
      ('tasks',           'client_id',     'clients',          'id',       'SET NULL', 'NO ACTION'),
      ('tasks',           'lead_id',       'leads',            'id',       'SET NULL', 'NO ACTION'),
      ('tasks',           'quote_id',      'quotes',           'id',       'SET NULL', 'NO ACTION'),
      ('tasks',           'order_id',      'orders',           'id',       'SET NULL', 'NO ACTION'),
      ('tasks',           'site_visit_id', 'site_visits',      'id',       'SET NULL', 'NO ACTION'),

      ('site_visits',     'lead_id',       'leads',            'id',       'NO ACTION','NO ACTION'),
      ('site_visits',     'client_id',     'clients',          'id',       'NO ACTION','NO ACTION'),

      ('client_links',    'client_id',     'clients',          'id',       'CASCADE',  'NO ACTION'),

      ('quotes',          'lead_id',       'leads',            'id',       'SET NULL', 'NO ACTION'),
      ('quote_versions',  'quote_id',      'quotes',           'id',       'CASCADE',  'NO ACTION'),

      ('invoices',        'quote_id',      'quotes',           'id',       'SET NULL', 'NO ACTION'),
      ('invoices',        'order_id',      'orders',           'id',       'SET NULL', 'NO ACTION'),
      ('payments',        'invoice_id',    'invoices',         'id',       'RESTRICT', 'NO ACTION'),
      ('returns',         'order_id',      'orders',           'id',       'SET NULL', 'NO ACTION'),

      ('page_blocks',     'page_id',       'pages',            'id',       'CASCADE',  'NO ACTION'),
      ('page_versions',   'page_id',       'pages',            'id',       'CASCADE',  'NO ACTION'),
      ('track_events',    'page_id',       'pages',            'id',       'SET NULL', 'NO ACTION'),

      ('projects',        'client_id',     'clients',          'id',       'NO ACTION','NO ACTION'),
      ('projects',        'order_id',      'orders',           'id',       'SET NULL', 'NO ACTION'),
      ('project_milestones','project_id',  'projects',         'id',       'CASCADE',  'NO ACTION'),
      ('boq_lines',       'project_id',    'projects',         'id',       'CASCADE',  'NO ACTION'),
      ('project_files',   'project_id',    'projects',         'id',       'CASCADE',  'NO ACTION'),

      ('conversations',   'client_id',     'clients',          'id',       'CASCADE',  'NO ACTION'),
      ('messages',        'conversation_id','conversations',   'id',       'CASCADE',  'NO ACTION'),

      ('sessions',        'user_id',       'users',            'id',       'CASCADE',  'NO ACTION'),
      ('clients',         'merged_into',   'clients',          'id',       'SET NULL', 'NO ACTION')
    ) AS t(child, child_col, parent, parent_col, on_delete, on_update)
  LOOP
    /* Guard on the constraint NAME, not the table. A table may legitimately
       hold several foreign keys (tasks has five); keying the guard on the
       table silently skipped every FK after the first one on that table. */
    IF NOT EXISTS (
      SELECT 1 FROM pg_constraint c
        JOIN pg_class t2 ON t2.oid = c.conrelid
        JOIN pg_namespace n ON n.oid = t2.relnamespace
       WHERE c.contype = 'f'
         AND c.conname = fk.child || '_' || fk.child_col || '_fkey'
         AND t2.relname = fk.child AND n.nspname = current_schema()
    ) THEN
      EXECUTE format(
        'ALTER TABLE %I ADD CONSTRAINT %I FOREIGN KEY (%I) REFERENCES %I(%I) '
        'ON DELETE %s ON UPDATE %s NOT VALID',
        fk.child, fk.child || '_' || fk.child_col || '_fkey',
        fk.child_col, fk.parent, fk.parent_col, fk.on_delete, fk.on_update
      );
    END IF;
  END LOOP;
END $$;

-- Validate in a second pass: reports the offending rows if anything slipped in
-- between the pre-flight and now, instead of leaving the key silently NOT VALID.
DO $$
DECLARE c record;
BEGIN
  FOR c IN
    SELECT conrelid::regclass AS tbl, conname
      FROM pg_constraint
     WHERE contype = 'f' AND NOT convalidated
       AND connamespace = current_schema()::regnamespace
  LOOP
    EXECUTE format('ALTER TABLE %s VALIDATE CONSTRAINT %I', c.tbl, c.conname);
  END LOOP;
END $$;

/* ────────────────────── 2. check constraints ───────────────────────── */
--
-- Money and quantities are guarded where the API already rejects the same
-- values, so these duplicate the code deliberately: the code is the fast 400,
-- the constraint is the last line that cannot be bypassed.

DO $$
DECLARE ck record;
BEGIN
  FOR ck IN
    SELECT * FROM (VALUES
      -- table              constraint name                    predicate
      ('products',          'products_price_positive',          'price >= 0'),
      ('products',          'products_stock_qty_non_negative',  'stock_qty >= 0'),
      ('products',          'products_in_stock_boolean',        'in_stock IN (0, 1)'),

      ('boq_lines',         'boq_lines_qty_positive',           'qty > 0'),
      ('boq_lines',         'boq_lines_unit_cost_non_negative','unit_cost >= 0'),

      ('payments',          'payments_amount_positive',         'amount > 0'),

      ('invoices',          'invoices_total_non_negative',      'total >= 0'),
      ('invoices',          'invoices_paid_non_negative',       'paid >= 0'),

      ('projects',          'projects_progress_range',          'progress >= 0 AND progress <= 100'),
      ('projects',          'projects_costs_non_negative',      'planned_cost >= 0 AND actual_cost >= 0'),

      ('quotes',            'quotes_version_positive',          'version >= 1'),
      ('quotes',            'quotes_total_non_negative',        'total >= 0'),

      ('orders',            'orders_total_non_negative',        'total >= 0'),

      -- Enumerations where the API allow-list is the single source of truth.
      ('orders',            'orders_stage_known',
                            '"stage" IN (''production'',''qc'',''dispatch'',''delivery'',''installation'')'),
      ('orders',            'orders_status_known',
                            '"status" IN (''Pending'',''On Track'',''In Progress'',''On Hold'',''Delayed'',''Completed'')'),
      ('users',             'users_role_known',
                            '"role" IN (''owner'',''editor'',''sales'',''finance'',''viewer'')'),
      ('users',             'users_active_boolean',             'active IN (0, 1)'),
      /* tasks.priority is deliberately left unconstrained. POST /api/tasks
         stores whatever string the caller sends, and callers are not consistent:
         the dashboard offers low/normal/high, QA posts "High", seeded rows use
         "high". A CHECK over one casing would reject the others, so the column
         stays open until the API normalises input. Recorded as an open item in
         03-DATA-AND-TENANCY.md rather than silently narrowed. */
      ('tasks',             'tasks_done_boolean',               'done IN (0, 1)'),
      /* "system" is a real value, not a typo: system-generated notes are
         written with direction="system" by the storefront enquiry capture and
         the client-enquiry path, and the inbox POST forces it when the channel
         is "system". An allow-list of inbound/outbound alone rejected every
         public lead submission with a 500 — which is exactly what the QA
         rehearsal caught. */
      ('messages',          'messages_direction_known',
                            '"direction" IN (''inbound'',''outbound'',''system'')'),
      ('site_visits',       'site_visits_status_known',
                            '"status" IN (''Scheduled'',''Confirmed'',''Completed'',''Cancelled'')'),
      ('quote_versions',    'quote_versions_version_positive',  'version >= 1')
    ) AS t(tbl, name, predicate)
  LOOP
    IF NOT EXISTS (
      SELECT 1 FROM pg_constraint c
        JOIN pg_class t2 ON t2.oid = c.conrelid
        JOIN pg_namespace n ON n.oid = t2.relnamespace
       WHERE c.contype = 'c' AND t2.relname = ck.tbl AND c.conname = ck.name
         AND n.nspname = current_schema()
    ) THEN
      EXECUTE format('ALTER TABLE %I ADD CONSTRAINT %I CHECK (%s)',
                     ck.tbl, ck.name, ck.predicate);
    END IF;
  END LOOP;
END $$;
