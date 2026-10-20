-- Lets the campaign edit form show, change and remove a campaign's contact
-- and social media links (campaign_social_media). Until now the site login
-- (bfep_app) could only add them, from the Add campaign form.
--
-- Run once as the schema owner `data`. Safe to run again.

BEGIN;

-- The form changes one link at a time, so each row needs an id.
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_attribute
    WHERE attrelid = 'campaign_social_media'::regclass AND attname = 'id' AND NOT attisdropped
  ) THEN
    ALTER TABLE campaign_social_media ADD COLUMN id serial;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conrelid = 'campaign_social_media'::regclass AND contype = 'p'
  ) THEN
    ALTER TABLE campaign_social_media ADD PRIMARY KEY (id);
  END IF;
END $$;

GRANT SELECT, INSERT, UPDATE, DELETE ON campaign_social_media TO bfep_app;

-- The id sequence, whatever it is called.
DO $$
DECLARE seq text := pg_get_serial_sequence('campaign_social_media', 'id');
BEGIN
  IF seq IS NOT NULL THEN
    EXECUTE format('GRANT USAGE ON SEQUENCE %s TO bfep_app', seq);
  END IF;
END $$;

COMMIT;
