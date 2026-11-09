-- 005_task_priority_vocabulary.sql
--
-- Close the last open vocabulary the code already treats as closed.
--
-- `tasks.priority` was deliberately left unconstrained in 002, and the reason
-- is recorded there: the dashboard sent low/normal/high, QA posted "High", and
-- seeds wrote both. A CHECK over any single casing would have rejected working
-- requests, so the column stayed free text and a bug could write a priority
-- nothing matches.
--
-- The API now normalises at the boundary (normPriority in apps/api/server.mjs),
-- so this constraint enforces the vocabulary instead of guessing at it. Order
-- matters and is the reason this is a separate migration rather than an edit to
-- 002: applied migrations are immutable, and the column has to be normalised by
-- code before the constraint can be added.
--
-- Existing rows are normalised first, in the same statement that adds the
-- constraint's precondition. Anything unrecognised becomes 'normal' — the same
-- default the API applies — rather than blocking the migration on a value that
-- only ever reached the database through the gap this closes.

UPDATE tasks
   SET priority = CASE lower(trim(coalesce(priority, '')))
                    WHEN 'low'    THEN 'low'
                    WHEN 'high'   THEN 'high'
                    WHEN 'urgent' THEN 'high'
                    WHEN 'normal' THEN 'normal'
                    WHEN 'medium' THEN 'normal'
                    WHEN 'med'    THEN 'normal'
                    ELSE 'normal'
                  END
 WHERE priority IS NULL
    OR priority NOT IN ('low', 'normal', 'high')
    OR priority <> lower(trim(priority));

-- Idempotent: 002 taught the lesson that a migration which silently adds nothing
-- is indistinguishable from one that failed. Re-running must be a no-op.
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint c
      JOIN pg_class t ON t.oid = c.conrelid
      JOIN pg_namespace n ON n.oid = t.relnamespace
     WHERE c.contype = 'c' AND t.relname = 'tasks'
       AND c.conname = 'tasks_priority_known' AND n.nspname = current_schema()
  ) THEN
    ALTER TABLE tasks ADD CONSTRAINT tasks_priority_known
      CHECK (priority IN ('low', 'normal', 'high'));
  END IF;
END $$;
