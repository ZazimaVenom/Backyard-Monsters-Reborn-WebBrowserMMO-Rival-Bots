CREATE TABLE IF NOT EXISTS users (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 name VARCHAR(32) NOT NULL,
 password_hash VARCHAR(255) NULL,
 is_bot TINYINT(1) NOT NULL DEFAULT 0,
 rank_points INT NOT NULL DEFAULT 0,
 wins INT UNSIGNED NOT NULL DEFAULT 0,
 losses INT UNSIGNED NOT NULL DEFAULT 0,
 next_turn_at BIGINT NOT NULL DEFAULT 0,
 created_at BIGINT NOT NULL,
 PRIMARY KEY (id), UNIQUE KEY unique_name (name),
 KEY ranking (rank_points DESC,id), KEY bot_turn (is_bot,next_turn_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS bases (
 user_id BIGINT UNSIGNED NOT NULL,
 state_json LONGTEXT NOT NULL,
 updated_at BIGINT NOT NULL,
 PRIMARY KEY (user_id),
 CONSTRAINT bases_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS battles (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 attacker_id BIGINT UNSIGNED NOT NULL,
 defender_id BIGINT UNSIGNED NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'active',
 state_json LONGTEXT NOT NULL,
 created_at BIGINT NOT NULL,
 updated_at BIGINT NOT NULL,
 PRIMARY KEY (id), KEY battle_attacker (attacker_id,status), KEY battle_defender (defender_id,status), KEY live_battles (status,updated_at),
 CONSTRAINT battle_attacker_user FOREIGN KEY (attacker_id) REFERENCES users(id),
 CONSTRAINT battle_defender_user FOREIGN KEY (defender_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS reports (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 user_id BIGINT UNSIGNED NOT NULL,
 battle_id BIGINT UNSIGNED NULL,
 title VARCHAR(150) NOT NULL,
 body_json LONGTEXT NOT NULL,
 created_at BIGINT NOT NULL,
 PRIMARY KEY (id), KEY owner_reports (user_id,id),
 CONSTRAINT reports_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT reports_battle FOREIGN KEY (battle_id) REFERENCES battles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS settings (
 name VARCHAR(100) NOT NULL,
 value LONGTEXT NOT NULL,
 PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS auth_attempts (
 identity_hash CHAR(64) NOT NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 window_start BIGINT NOT NULL,
 PRIMARY KEY (identity_hash), KEY expired_attempts (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
