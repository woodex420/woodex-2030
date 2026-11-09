-- 014 · CRM depth — ROLLBACK
--
--   node scripts/migrate.mjs --dsn <url> --rollback 014
--
-- Refuses once a timeline or an attachment exists.
--
-- `activities` is the record of who spoke to whom and when. Dropping it does
-- not undo the conversations — it destroys the only evidence they happened, and
-- unlike a journal entry there is no second copy in a document. Attachments are
-- refused for a different reason: the table holds pointers, so a rolled-back
-- schema leaves files in the object store that nothing references and nobody
-- can find.

DO $$
DECLARE
  n_activities  BIGINT;
  n_attachments BIGINT;
BEGIN
  SELECT count(*) INTO n_activities  FROM activities;
  SELECT count(*) INTO n_attachments FROM attachments;

  IF n_activities > 0 OR n_attachments > 0 THEN
    RAISE EXCEPTION
      'refusing to roll back 014: % activity row(s) and % attachment(s) exist. A timeline is evidence of contact; dropping it erases who spoke to whom.',
      n_activities, n_attachments;
  END IF;
END $$;

DROP INDEX IF EXISTS idx_clients_company;
DROP INDEX IF EXISTS idx_leads_company;
/* The columns carry named FKs, so they go first — dropping a column drops its
   constraint too, but naming them keeps the rollback legible and keeps the
   statements working against a database where only the constraint landed. */
ALTER TABLE IF EXISTS clients DROP CONSTRAINT IF EXISTS clients_company_fkey;
ALTER TABLE IF EXISTS leads   DROP CONSTRAINT IF EXISTS leads_company_fkey;
ALTER TABLE IF EXISTS clients DROP COLUMN IF EXISTS company_id;
ALTER TABLE IF EXISTS leads   DROP COLUMN IF EXISTS company_id;

DROP TRIGGER IF EXISTS trg_activities_no_change ON activities;
DROP FUNCTION IF EXISTS activities_are_append_only();

DROP TABLE IF EXISTS activities;
DROP TABLE IF EXISTS attachments;
DROP TABLE IF EXISTS meetings;
DROP TABLE IF EXISTS contacts;
DROP TABLE IF EXISTS companies;
