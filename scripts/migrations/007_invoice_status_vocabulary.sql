-- 007_invoice_status_vocabulary.sql
--
-- Close `invoices.status` — the last open enum, and the one whose column does
-- not mean what its values suggest.
--
-- 002 left it open because "the PATCH route accepts an arbitrary status string
-- (no allow-list), while the dashboard's own union type offers Cancelled.
-- Constraining it would turn a currently-accepted request into a 500." That was
-- accurate about the code and wrong about the fix: the answer is a boundary that
-- rejects bad input with a 400, which now exists.
--
-- WHY THIS COLUMN IS DIFFERENT FROM THE PREVIOUS TWO
--
-- Only four values are ever STORED:
--
--     Issued · Partially Paid · Paid · Cancelled
--
-- "Overdue" is not among them. decorateInvoice derives it at read time from the
-- due date and the remaining balance, which is the right design — an invoice
-- becomes overdue because time passed, not because anyone wrote it down. Storing
-- it would freeze a value that is supposed to stay recomputed, and the first
-- payment would leave a stale "Overdue" behind. The CHECK below therefore
-- deliberately REJECTS 'Overdue', and the API answers 400 if someone tries to
-- set it rather than silently storing a lie.
--
-- "Draft" is rejected for a different reason: the dashboard's InvoiceStatus
-- union offers it, but no code path produces an invoice in that state. A
-- constraint that admitted it would permit a value nothing can create.
--
-- "Refunded" is unreachable and deliberately not admitted. The payment route
-- looked like it could produce it when `paid` exceeds `total`, but `amount > 0`
-- is enforced and `paid >= total` maps to 'Paid', so `paid` is never negative.
-- Recording the dead branch here so a future change that activates it fails the
-- constraint loudly instead of writing a status nothing understands.
--
-- THE TRANSITION A CHECK CANNOT STOP
--
-- A constraint guards values, not sequences. Recording a payment against a
-- Cancelled invoice used to set the status unconditionally from the running
-- total, flipping it back to Paid and quietly undoing the cancellation. That is
-- a code-level guard in the payments route (409), shipped with this migration —
-- not something a CHECK can express.

-- Existing rows first. Anything outside the stored set is mapped the way the
-- API would map it, or dropped to 'Issued' where nothing sensible exists: the
-- alternative is a migration that refuses to run on a value only reachable
-- through the gap this closes.
UPDATE invoices
   SET status = CASE lower(trim(replace(replace(coalesce(status, ''), '_', ' '), '-', ' ')))
                 WHEN 'issued'          THEN 'Issued'
                 WHEN 'issue'           THEN 'Issued'
                 WHEN 'unpaid'          THEN 'Issued'
                 WHEN 'open'            THEN 'Issued'
                 WHEN 'pending'         THEN 'Issued'
                 WHEN 'sent'            THEN 'Issued'
                 WHEN 'new'             THEN 'Issued'
                 WHEN 'draft'           THEN 'Issued'
                 WHEN 'overdue'         THEN 'Issued'
                 WHEN 'partially paid'  THEN 'Partially Paid'
                 WHEN 'partial'         THEN 'Partially Paid'
                 WHEN 'part paid'       THEN 'Partially Paid'
                 WHEN 'partiallypaid'   THEN 'Partially Paid'
                 WHEN 'paid'            THEN 'Paid'
                 WHEN 'settled'         THEN 'Paid'
                 WHEN 'complete'        THEN 'Paid'
                 WHEN 'completed'       THEN 'Paid'
                 WHEN 'cancelled'       THEN 'Cancelled'
                 WHEN 'canceled'        THEN 'Cancelled'
                 WHEN 'cancel'          THEN 'Cancelled'
                 WHEN 'void'            THEN 'Cancelled'
                 WHEN 'voided'          THEN 'Cancelled'
                 ELSE 'Issued'
               END
 WHERE status IS NULL
    OR status NOT IN ('Issued', 'Partially Paid', 'Paid', 'Cancelled');

-- Idempotent: re-running must add nothing. A migration that silently does
-- nothing is indistinguishable from one that failed.
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint c
      JOIN pg_class t ON t.oid = c.conrelid
      JOIN pg_namespace n ON n.oid = t.relnamespace
     WHERE c.contype = 'c' AND t.relname = 'invoices'
       AND c.conname = 'invoices_status_known' AND n.nspname = current_schema()
  ) THEN
    ALTER TABLE invoices ADD CONSTRAINT invoices_status_known
      CHECK (status IN ('Issued', 'Partially Paid', 'Paid', 'Cancelled'));
  END IF;
END $$;
