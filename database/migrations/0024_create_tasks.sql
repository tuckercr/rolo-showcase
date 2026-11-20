-- Tasks: one-off to-dos with a checkbox (Dana's request, 2026-11-20).
-- Deliberately separate from the follow-up engine. A contact's
-- next_touch_date is its rhythm and is rescheduled by logging a touch. A
-- task is a single thing to do, optionally about a contact or an
-- organization, and ticking it never moves a follow-up date.
--
-- owner_id follows the same lens as contacts.owner_id (migration 0021).
-- NULL means shared, so the task appears on every user's personalized
-- surfaces. An owned task appears only on its owner's. Ownership is a lens
-- and never an access control, so every task stays visible and editable by
-- everyone.
--
-- A task links to at most ONE record. With contact_id set the organization
-- is read through the contact, so it can never disagree with the contact's
-- own. organization_id is for tasks about an organization with no single
-- person attached. Free-floating tasks leave both NULL.
--
-- Open/done is derived from completed_at (UTC), never stored separately.
-- completed_by records who ticked it and is cleared again on reopen.
--
-- Tasks of a trashed contact are hidden along with the contact and return
-- when it is restored. That filter lives in TaskModel, not here.
--
-- The organization key deliberately has no ON DELETE action, unlike
-- contacts.organization_id (SET NULL). MariaDB refuses a CHECK on a column
-- that a foreign key action can rewrite, and the one-link CHECK is the
-- invariant worth having. Nothing in the app deletes organizations today.
-- If that is ever built, it has to say what happens to the organization's
-- tasks instead of having them silently turn free-floating.
CREATE TABLE tasks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    notes TEXT NULL,
    due_date DATE NULL,
    owner_id INT UNSIGNED NULL,
    contact_id INT UNSIGNED NULL,
    organization_id INT UNSIGNED NULL,
    completed_at DATETIME NULL,
    completed_by INT UNSIGNED NULL,
    created_by INT UNSIGNED NOT NULL,
    updated_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tasks_open_due (completed_at, due_date),
    KEY idx_tasks_owner (owner_id),
    KEY idx_tasks_contact (contact_id),
    KEY idx_tasks_organization (organization_id),
    CONSTRAINT fk_tasks_owner FOREIGN KEY (owner_id) REFERENCES users (id),
    CONSTRAINT fk_tasks_contact FOREIGN KEY (contact_id)
        REFERENCES contacts (id) ON DELETE CASCADE,
    CONSTRAINT fk_tasks_organization FOREIGN KEY (organization_id) REFERENCES organizations (id),
    CONSTRAINT fk_tasks_completed_by FOREIGN KEY (completed_by) REFERENCES users (id),
    CONSTRAINT fk_tasks_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_tasks_updated_by FOREIGN KEY (updated_by) REFERENCES users (id),
    CONSTRAINT chk_tasks_single_link CHECK (contact_id IS NULL OR organization_id IS NULL),
    CONSTRAINT chk_tasks_completion CHECK ((completed_at IS NULL) = (completed_by IS NULL))
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
