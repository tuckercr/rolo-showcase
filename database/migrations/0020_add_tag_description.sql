-- Tags get an optional one-line description for the /tags management page
-- (2026-11-18). A described tag is curated: orphan pruning keeps it even
-- when no contact carries it yet, so a planned grouping can exist before
-- its first member.
ALTER TABLE tags
    ADD COLUMN description VARCHAR(255) NOT NULL DEFAULT '' AFTER name;
