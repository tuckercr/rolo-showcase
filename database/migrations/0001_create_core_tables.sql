-- Core schema for Project Rolo — see docs/SCHEMA.md for the full rationale.
-- All timestamps are UTC (the app sets the session time_zone to +00:00).

CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    -- Google's stable account ID from the OAuth ID token; filled in on first login.
    google_sub VARCHAR(255) NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'member',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_google_sub (google_sub)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- The relationship-status pipeline, ordered and editable (not a hardcoded enum).
CREATE TABLE stages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    sort_order INT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stages_name (name)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE organizations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    org_type ENUM ('startup', 'cro', 'university', 'investor', 'media', 'other')
        NOT NULL DEFAULT 'other',
    website VARCHAR(255) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_organizations_name (name)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- The core table. Only name is required at creation (SOP "under 30 seconds" rule).
-- relationship_status references stages.name (ON UPDATE CASCADE) so the pipeline
-- stays editable while the DB still guarantees integrity.
CREATE TABLE contacts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    organization_id INT UNSIGNED NULL,
    title VARCHAR(255) NULL,
    relationship_status VARCHAR(100) NOT NULL DEFAULT 'New/Captured',
    relationship_type ENUM (
        'prospect', 'client', 'ecosystem', 'media', 'investor', 'mentor_advisor'
    ) NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    linkedin_url VARCHAR(255) NULL,
    last_touch_date DATE NULL,
    next_touch_date DATE NULL,
    -- When set, takes precedence over the reminder_rules default for this contact.
    cadence_days_override INT UNSIGNED NULL,
    -- One memorable line about the person.
    human_detail VARCHAR(500) NULL,
    created_by INT UNSIGNED NOT NULL,
    updated_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_contacts_name (name),
    -- Monday dashboard: WHERE next_touch_date <= CURDATE() + INTERVAL 7 DAY ORDER BY next_touch_date
    KEY idx_contacts_next_touch_date (next_touch_date),
    CONSTRAINT fk_contacts_organization FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE SET NULL,
    CONSTRAINT fk_contacts_status FOREIGN KEY (relationship_status)
        REFERENCES stages (name) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_contacts_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_contacts_updated_by FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE tags (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tags_name (name)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE contact_tags (
    contact_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (contact_id, tag_id),
    CONSTRAINT fk_contact_tags_contact FOREIGN KEY (contact_id)
        REFERENCES contacts (id) ON DELETE CASCADE,
    CONSTRAINT fk_contact_tags_tag FOREIGN KEY (tag_id)
        REFERENCES tags (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Append-only interaction log: no updated_at/updated_by by design — never edit
-- history, only add (docs/SCHEMA.md).
CREATE TABLE activities (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    contact_id INT UNSIGNED NOT NULL,
    organization_id INT UNSIGNED NULL,
    activity_type ENUM (
        'event_meeting', 'email', 'call', 'coffee', 'linkedin_message', 'intro_made', 'other'
    ) NOT NULL,
    activity_date DATE NOT NULL,
    summary TEXT NULL,
    follow_up_needed TINYINT(1) NOT NULL DEFAULT 0,
    follow_up_date DATE NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_activities_contact_date (contact_id, activity_date),
    CONSTRAINT fk_activities_contact FOREIGN KEY (contact_id)
        REFERENCES contacts (id) ON DELETE CASCADE,
    CONSTRAINT fk_activities_organization FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE SET NULL,
    CONSTRAINT fk_activities_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Deliberately separate from contacts; most contacts should never get one.
CREATE TABLE opportunities (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    contact_id INT UNSIGNED NOT NULL,
    organization_id INT UNSIGNED NULL,
    stage ENUM (
        'potential_need', 'discovery', 'scoped', 'proposal_sent', 'negotiation', 'won', 'lost'
    ) NOT NULL DEFAULT 'potential_need',
    service_type VARCHAR(255) NULL,
    next_step VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_opportunities_contact FOREIGN KEY (contact_id)
        REFERENCES contacts (id) ON DELETE CASCADE,
    CONSTRAINT fk_opportunities_organization FOREIGN KEY (organization_id)
        REFERENCES organizations (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- The "easily adjustable" piece: cadence is data, not code. One rule per stage;
-- a stage with no row (Closed / Not a Fit) generates no reminder.
CREATE TABLE reminder_rules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    relationship_status VARCHAR(100) NOT NULL,
    rule_type ENUM ('fixed_days', 'cadence_from_last_touch', 'business_days') NOT NULL,
    value_days INT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reminder_rules_status (relationship_status),
    CONSTRAINT fk_reminder_rules_status FOREIGN KEY (relationship_status)
        REFERENCES stages (name) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_reminder_rules_updated_by FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
