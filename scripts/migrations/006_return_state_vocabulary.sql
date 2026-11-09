-- 006_return_state_vocabulary.sql
--
-- Close `returns.state` — the vocabulary that was not just cosmetic.
--
-- 002 left this column open and gave a reason: "the demo seed writes
-- 'Approved', 'Rejected' and 'Completed'". That reason was wrong. Nothing in
-- the codebase ever writes those to a return; every insert, the seed included,
-- writes "Requested", and every update goes through PATCH. The column was open
-- because nobody normalised it, not because the values were legitimate.
--
-- Leaving it open had teeth. PATCH /api/returns/:id restocks the order's units
-- only when the next state is literally "Closed":
--
--     if (next === "Closed" && r.state !== "Closed" && r.order_id) { ... }
--
-- So "closed", "CLOSED", "Complete" or "Done" resolved the RMA and silently
-- skipped the restock. The return looked finished; the units stayed gone from
-- inventory, with no ledger row explaining the gap. That is a data-loss bug
-- wearing the costume of a missing constraint.
--
-- Order matters, and is why this is a new file rather than an edit to 002:
-- applied migrations are immutable, and the column must be normalised by code
-- (normReturnState in apps/api/server.mjs) before the constraint can be honest.
--
-- The alias map below is deliberately identical to RETURN_STATE_ALIASES in the
-- server. If the two ever drift, the vocabulary test that compares this CHECK
-- against the values the code writes will fail — which is the point.

UPDATE returns
   SET state = CASE lower(trim(replace(replace(coalesce(state, ''), '_', ' '), '-', ' ')))
                 WHEN 'requested'  THEN 'Requested'
                 WHEN 'request'    THEN 'Requested'
                 WHEN 'open'       THEN 'Requested'
                 WHEN 'new'        THEN 'Requested'
                 WHEN 'inspecting' THEN 'Inspecting'
                 WHEN 'inspection' THEN 'Inspecting'
                 WHEN 'in transit' THEN 'Inspecting'
                 WHEN 'refunding'  THEN 'Refunding'
                 WHEN 'refund'     THEN 'Refunding'
                 WHEN 'refunded'   THEN 'Refunding'
                 WHEN 'closed'     THEN 'Closed'
                 WHEN 'close'      THEN 'Closed'
                 WHEN 'complete'   THEN 'Closed'
                 WHEN 'completed'  THEN 'Closed'
                 WHEN 'done'       THEN 'Closed'
                 WHEN 'resolved'   THEN 'Closed'
                 WHEN 'approved'   THEN 'Closed'
                 WHEN 'rejected'   THEN 'Closed'
                 ELSE 'Requested'
               END
 WHERE state IS NULL
    OR state NOT IN ('Requested', 'Inspecting', 'Refunding', 'Closed');

-- Idempotent: re-running must add nothing. 002 taught that a migration which
-- silently does nothing is indistinguishable from one that failed.
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint c
      JOIN pg_class t ON t.oid = c.conrelid
      JOIN pg_namespace n ON n.oid = t.relnamespace
     WHERE c.contype = 'c' AND t.relname = 'returns'
       AND c.conname = 'returns_state_known' AND n.nspname = current_schema()
  ) THEN
    ALTER TABLE returns ADD CONSTRAINT returns_state_known
      CHECK (state IN ('Requested', 'Inspecting', 'Refunding', 'Closed'));
  END IF;
END $$;
