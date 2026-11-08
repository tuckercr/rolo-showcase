-- Trash-can deletion for contacts: soft delete with restore. A trashed
-- contact keeps its full activity history and vanishes from lists, search,
-- the dashboard, and the daily summary email until restored.

ALTER TABLE contacts
    ADD COLUMN deleted_at DATETIME NULL AFTER updated_at,
    ADD COLUMN deleted_by INT UNSIGNED NULL AFTER deleted_at,
    ADD CONSTRAINT fk_contacts_deleted_by FOREIGN KEY (deleted_by) REFERENCES users (id);
