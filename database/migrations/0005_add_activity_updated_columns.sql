-- Activities are now editable in place (decision 2026-07-10, replacing the
-- original append-only rule): latest content only, no revision history, but
-- the usual data trail — who last edited and when, surfaced in the UI.

ALTER TABLE activities
    ADD COLUMN updated_by INT UNSIGNED NULL AFTER created_by,
    ADD COLUMN updated_at DATETIME NULL AFTER created_at,
    ADD CONSTRAINT fk_activities_updated_by FOREIGN KEY (updated_by) REFERENCES users (id);
