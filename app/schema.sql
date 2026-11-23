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
 defender_id BIGINT UNSIGNED NULL,
 target_kind VARCHAR(20) NOT NULL DEFAULT 'home',
 target_id BIGINT UNSIGNED NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'active',
 state_json LONGTEXT NOT NULL,
 created_at BIGINT NOT NULL,
 updated_at BIGINT NOT NULL,
 PRIMARY KEY (id), KEY battle_attacker (attacker_id,status), KEY battle_defender (defender_id,status), KEY live_battles (status,updated_at), KEY battle_target (target_kind,target_id,status),
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
CREATE TABLE IF NOT EXISTS world_sites (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 kind VARCHAR(20) NOT NULL,
 q INT NOT NULL,
 r INT NOT NULL,
 owner_id BIGINT UNSIGNED NULL,
 home_user_id BIGINT UNSIGNED NULL,
 name VARCHAR(80) NOT NULL,
 level INT NOT NULL DEFAULT 1,
 state_json LONGTEXT NULL,
 revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
 updated_at BIGINT NOT NULL,
 PRIMARY KEY (id), UNIQUE KEY world_position (q,r), UNIQUE KEY world_home (home_user_id), KEY world_owner (owner_id,kind),
 CONSTRAINT world_owner_user FOREIGN KEY (owner_id) REFERENCES users(id),
 CONSTRAINT world_home_user FOREIGN KEY (home_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS alliances (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 name VARCHAR(30) NOT NULL,
 description VARCHAR(200) NOT NULL DEFAULT '',
 leader_id BIGINT UNSIGNED NOT NULL,
 bot_charter TINYINT UNSIGNED NULL,
 revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
 created_at BIGINT NOT NULL,
 PRIMARY KEY (id), UNIQUE KEY alliance_name (name), UNIQUE KEY bot_charter_slot (bot_charter),
 CONSTRAINT alliance_leader FOREIGN KEY (leader_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS alliance_members (
 user_id BIGINT UNSIGNED NOT NULL,
 alliance_id BIGINT UNSIGNED NOT NULL,
 joined_at BIGINT NOT NULL,
 PRIMARY KEY (user_id), KEY alliance_roster (alliance_id,user_id),
 CONSTRAINT member_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT member_group FOREIGN KEY (alliance_id) REFERENCES alliances(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS alliance_requests (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 alliance_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 kind VARCHAR(8) NOT NULL,
 created_at BIGINT NOT NULL,
 PRIMARY KEY (id), UNIQUE KEY alliance_request_pair (alliance_id,user_id), KEY keeper_invites (user_id),
 CONSTRAINT request_group FOREIGN KEY (alliance_id) REFERENCES alliances(id) ON DELETE CASCADE,
 CONSTRAINT request_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS keeper_friends (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 low_id BIGINT UNSIGNED NOT NULL,
 high_id BIGINT UNSIGNED NOT NULL,
 requested_by BIGINT UNSIGNED NOT NULL,
 accepted TINYINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY (id), UNIQUE KEY friend_pair (low_id,high_id), KEY friend_high (high_id,accepted),
 KEY friend_outgoing (requested_by,accepted),
 CONSTRAINT friend_low FOREIGN KEY (low_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT friend_high FOREIGN KEY (high_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS keeper_gifts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 sender_id BIGINT UNSIGNED NOT NULL,
 recipient_id BIGINT UNSIGNED NOT NULL,
 sent_at BIGINT NOT NULL,
 resource VARCHAR(8) NOT NULL,
 basis_points SMALLINT UNSIGNED NOT NULL,
 remaining INT UNSIGNED NULL,
 claimed_at BIGINT NULL,
 PRIMARY KEY (id), KEY gift_cooldown (sender_id,recipient_id,sent_at), KEY gift_inbox (recipient_id,claimed_at,id),
 CONSTRAINT gift_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT gift_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS keeper_mail (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 sender_id BIGINT UNSIGNED NOT NULL,
 recipient_id BIGINT UNSIGNED NOT NULL,
 client_key CHAR(32) NOT NULL,
 body VARCHAR(1200) NOT NULL,
 sent_at BIGINT NOT NULL,
 read_at BIGINT NULL,
 PRIMARY KEY (id), UNIQUE KEY mail_retry (sender_id,client_key),
 KEY mail_thread (sender_id,recipient_id,id), KEY mail_unread (recipient_id,read_at,id),
 CONSTRAINT mail_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT mail_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS alliance_stances (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 alliance_id BIGINT UNSIGNED NOT NULL,
 target_id BIGINT UNSIGNED NOT NULL,
 stance VARCHAR(8) NOT NULL,
 PRIMARY KEY (id), UNIQUE KEY alliance_stance_pair (alliance_id,target_id),
 CONSTRAINT stance_from FOREIGN KEY (alliance_id) REFERENCES alliances(id) ON DELETE CASCADE,
 CONSTRAINT stance_to FOREIGN KEY (target_id) REFERENCES alliances(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS alliance_wars (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 challenger_id BIGINT UNSIGNED NULL,
 opponent_id BIGINT UNSIGNED NULL,
 challenger_name VARCHAR(30) NOT NULL,
 opponent_name VARCHAR(30) NOT NULL,
 state VARCHAR(12) NOT NULL,
 created_at BIGINT NOT NULL,
 starts_at BIGINT NULL,
 ends_at BIGINT NOT NULL,
 rosters LONGTEXT NULL,
 PRIMARY KEY (id), KEY wars_from (challenger_id,state,ends_at), KEY wars_to (opponent_id,state,ends_at),
 CONSTRAINT war_from FOREIGN KEY (challenger_id) REFERENCES alliances(id) ON DELETE SET NULL,
 CONSTRAINT war_to FOREIGN KEY (opponent_id) REFERENCES alliances(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS alliance_war_battles (
 battle_id BIGINT UNSIGNED NOT NULL,
 war_id BIGINT UNSIGNED NOT NULL,
 winner_side TINYINT UNSIGNED NOT NULL,
 finished_at BIGINT NOT NULL,
 PRIMARY KEY (battle_id), KEY war_score (war_id,winner_side),
 CONSTRAINT war_battle_match FOREIGN KEY (battle_id) REFERENCES battles(id),
 CONSTRAINT war_battle_war FOREIGN KEY (war_id) REFERENCES alliance_wars(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
