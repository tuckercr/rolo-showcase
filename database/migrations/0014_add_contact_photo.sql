-- Profile photos for contacts (2026-10-13). The column stores only the
-- random on-disk filename of the processed 400x400 JPEG under
-- storage/photos (outside the web root); NULL means "no photo", in which
-- case the UI renders an initials avatar.

ALTER TABLE contacts
    ADD COLUMN photo VARCHAR(72) NULL AFTER human_detail;
