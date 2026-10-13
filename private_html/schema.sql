CREATE TABLE registrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    participant_name VARCHAR(160) NOT NULL,
    participant_email VARCHAR(254) NOT NULL,
    participant_phone VARCHAR(40) NULL,
    birth_date DATE NULL,
    gender VARCHAR(20) NULL,
    guardian_name VARCHAR(160) NULL,
    guardian_phone VARCHAR(40) NULL,
    transport_preference VARCHAR(30) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_registrations_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
