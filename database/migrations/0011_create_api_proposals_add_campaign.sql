-- Piece 2 of the agent integration: write actions from agents land here as
-- PROPOSALS, never as direct writes. A human reviews them at /proposals and
-- approving applies the change (attributed to the approving user). The
-- unique idempotency key means a retried agent call can't queue duplicates.

CREATE TABLE api_proposals (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_name VARCHAR(20) NOT NULL,
    action VARCHAR(40) NOT NULL,
    payload TEXT NOT NULL,
    summary VARCHAR(500) NOT NULL,
    idempotency_key VARCHAR(100) NULL,
    status ENUM ('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    error VARCHAR(500) NULL,
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_proposals_idempotency (idempotency_key),
    KEY idx_proposals_status (status, id),
    CONSTRAINT fk_proposals_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 'campaign' activity type: agent-logged marketing touches ("sent LinkedIn
-- post series"). Not offered in the human log form; by default it does NOT
-- advance last/next touch, so bulk campaign logging can't clear the queue.
ALTER TABLE activities
    MODIFY COLUMN activity_type ENUM (
        'event_meeting', 'email', 'call', 'coffee', 'linkedin_message',
        'intro_made', 'other', 'snoozed', 'campaign'
    ) NOT NULL;
