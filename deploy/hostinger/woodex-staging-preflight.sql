-- WOODEX STAGING: READ-ONLY DATABASE PREFLIGHT
-- Select u128159657_reimagin in phpMyAdmin before importing this file.
-- This is NOT the Laravel schema and does NOT install the application.
-- No CREATE, ALTER, INSERT, UPDATE, DELETE, DROP, GRANT or migrations occur.
-- Do not share credentials or application data with the results.

SELECT
    DATABASE() AS selected_database,
    VERSION() AS server_version,
    @@character_set_database AS database_character_set,
    @@collation_database AS database_collation;

SELECT
    CASE
        WHEN DATABASE() IS NULL THEN 'STOP: select the staging database first'
        WHEN DATABASE() <> 'u128159657_reimagin' THEN 'STOP: unexpected database selected'
        WHEN EXISTS (
            SELECT 1 FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
        ) THEN 'REVIEW REQUIRED: database contains tables or views; do not overwrite'
        ELSE 'No tables or views found; application migrations are still pending'
    END AS readiness_note;

SELECT TABLE_NAME AS existing_object, TABLE_TYPE AS object_type, ENGINE AS storage_engine
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
ORDER BY TABLE_NAME;
