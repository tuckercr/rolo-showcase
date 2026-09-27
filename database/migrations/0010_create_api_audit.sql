-- Audit trail for the agent-facing API (Jessica's integration spec): every
-- request is recorded with the token that made it. Also the basis for the
-- per-token rate limit (requests in the last minute).

CREATE TABLE api_audit (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_name VARCHAR(20) NOT NULL,
    method VARCHAR(8) NOT NULL,
    path VARCHAR(255) NOT NULL,
    query_string VARCHAR(1000) NULL,
    response_code SMALLINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_api_audit_token_time (token_name, created_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
