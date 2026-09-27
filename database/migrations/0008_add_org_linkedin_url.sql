-- Organizations get a LinkedIn URL alongside the website (Jessica's request:
-- a place for a URL on company info).

ALTER TABLE organizations
    ADD COLUMN linkedin_url VARCHAR(255) NULL AFTER website;
