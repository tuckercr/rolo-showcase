-- Who tends this relationship. NULL = shared: the contact appears on every
-- user's personalized follow-up surfaces (dashboard "Mine" view, and later
-- the per-user daily email and Slack updates). An owned contact surfaces
-- only for its owner there; the record itself stays fully visible and
-- editable by everyone — ownership is a lens, not an access control.
-- Existing rows stay NULL, so behavior is unchanged until contacts are
-- assigned.
ALTER TABLE contacts
    ADD COLUMN owner_id INT UNSIGNED NULL AFTER relationship_type,
    ADD INDEX idx_contacts_owner (owner_id),
    ADD CONSTRAINT fk_contacts_owner FOREIGN KEY (owner_id) REFERENCES users (id);
