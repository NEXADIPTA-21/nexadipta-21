-- SKANEXA V.12.1 -> PostgreSQL compatibility migration
-- Run this ONLY if you already ran SKANEXA_supabase_schema.sql before this file.
-- It keeps boolean-like application fields as SMALLINT 0/1 because the PHP app
-- reads/writes these values as integers.

ALTER TABLE participants
  ALTER COLUMN has_voted TYPE SMALLINT USING CASE WHEN has_voted THEN 1 ELSE 0 END,
  ALTER COLUMN has_voted SET DEFAULT 0;

ALTER TABLE poll_settings
  ALTER COLUMN allow_change_vote TYPE SMALLINT USING CASE WHEN allow_change_vote THEN 1 ELSE 0 END,
  ALTER COLUMN show_results TYPE SMALLINT USING CASE WHEN show_results THEN 1 ELSE 0 END,
  ALTER COLUMN randomize_candidates TYPE SMALLINT USING CASE WHEN randomize_candidates THEN 1 ELSE 0 END;

ALTER TABLE poll_questions
  ALTER COLUMN required TYPE SMALLINT USING CASE WHEN required THEN 1 ELSE 0 END,
  ALTER COLUMN required SET DEFAULT 1;

ALTER TABLE poll_question_options
  ALTER COLUMN ends_poll TYPE SMALLINT USING CASE WHEN ends_poll THEN 1 ELSE 0 END,
  ALTER COLUMN ends_poll SET DEFAULT 0;

ALTER TABLE poll_answers
  ALTER COLUMN is_draft TYPE SMALLINT USING CASE WHEN is_draft THEN 1 ELSE 0 END,
  ALTER COLUMN is_draft SET DEFAULT 0;

ALTER TABLE website_timeline
  ALTER COLUMN is_active TYPE SMALLINT USING CASE WHEN is_active THEN 1 ELSE 0 END,
  ALTER COLUMN is_active SET DEFAULT 1;

ALTER TABLE website_gallery
  ALTER COLUMN is_active TYPE SMALLINT USING CASE WHEN is_active THEN 1 ELSE 0 END,
  ALTER COLUMN is_active SET DEFAULT 1;

ALTER TABLE website_contacts
  ALTER COLUMN is_active TYPE SMALLINT USING CASE WHEN is_active THEN 1 ELSE 0 END,
  ALTER COLUMN is_active SET DEFAULT 1;

-- Safety constraints for the 0/1 fields.
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='participants_has_voted_bool') THEN
    ALTER TABLE participants ADD CONSTRAINT participants_has_voted_bool CHECK (has_voted IN (0,1));
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='poll_settings_flags_bool') THEN
    ALTER TABLE poll_settings ADD CONSTRAINT poll_settings_flags_bool CHECK (allow_change_vote IN (0,1) AND show_results IN (0,1) AND randomize_candidates IN (0,1));
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='poll_questions_required_bool') THEN
    ALTER TABLE poll_questions ADD CONSTRAINT poll_questions_required_bool CHECK (required IN (0,1));
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='pqo_ends_poll_bool') THEN
    ALTER TABLE poll_question_options ADD CONSTRAINT pqo_ends_poll_bool CHECK (ends_poll IN (0,1));
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname='poll_answers_draft_bool') THEN
    ALTER TABLE poll_answers ADD CONSTRAINT poll_answers_draft_bool CHECK (is_draft IN (0,1));
  END IF;
END $$;
