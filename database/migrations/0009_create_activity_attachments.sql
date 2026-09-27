-- Documents attached to logged interactions (Jessica's request #2).
-- Files live on disk OUTSIDE the web root (storage/attachments) under
-- random names; original filenames exist only in this table for display.
-- Downloads go through the auth-gated /attachments/{id} route.

CREATE TABLE activity_attachments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    activity_id INT UNSIGNED NOT NULL,
    stored_name VARCHAR(100) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attachments_stored_name (stored_name),
    CONSTRAINT fk_attachments_activity FOREIGN KEY (activity_id)
        REFERENCES activities (id) ON DELETE CASCADE,
    CONSTRAINT fk_attachments_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
