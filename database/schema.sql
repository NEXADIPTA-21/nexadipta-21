-- =========================================================
-- SKEMA DATABASE SISTEM POLLING FOTO ANGKATAN - V8
-- Untuk instalasi baru melalui phpMyAdmin.
-- DATABASE YANG SUDAH DIMIGRASI TIDAK PERLU IMPORT ULANG FILE INI.
-- =========================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS classes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  normalized_name VARCHAR(100) NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_class_normalized (normalized_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS participants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  normalized_name VARCHAR(150) NOT NULL,
  class_id INT NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  has_voted TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_participant_name_class (normalized_name, class_id),
  KEY idx_class (class_id),
  CONSTRAINT fk_participant_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS polls (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  description TEXT,
  poll_type ENUM('single_choice','multiple_choice','yes_no','image_choice','questionnaire') NOT NULL DEFAULT 'single_choice',
  access_mode ENUM('token_verification','verification_only','token_only') NOT NULL DEFAULT 'token_verification',
  token_hash CHAR(64) NULL,
  token_rotated_at DATETIME NULL,
  status ENUM('draft','scheduled','active','paused','closed','archived','inactive') NOT NULL DEFAULT 'draft',
  start_at DATETIME NULL,
  end_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS candidates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  poll_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  image_url VARCHAR(255),
  description TEXT,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_poll (poll_id),
  CONSTRAINT fk_candidate_poll FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS poll_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  poll_id INT NOT NULL UNIQUE,
  min_choices INT NOT NULL DEFAULT 1,
  max_choices INT NOT NULL DEFAULT 1,
  allow_change_vote TINYINT(1) NOT NULL DEFAULT 0,
  show_results TINYINT(1) NOT NULL DEFAULT 0,
  randomize_candidates TINYINT(1) NOT NULL DEFAULT 0,
  max_questions INT NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_poll_settings_poll FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS poll_questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  poll_id INT NOT NULL,
  question_text VARCHAR(500) NOT NULL,
  image_url VARCHAR(255) NULL,
  question_type ENUM('single_choice','multiple_choice','yes_no') NOT NULL DEFAULT 'single_choice',
  sort_order INT NOT NULL DEFAULT 0,
  required TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_poll_questions_poll (poll_id),
  CONSTRAINT fk_poll_questions_poll FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS poll_question_options (
  id INT AUTO_INCREMENT PRIMARY KEY,
  question_id INT NOT NULL,
  option_text VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  ends_poll TINYINT(1) NOT NULL DEFAULT 0,
  next_question_id INT NULL,
  target_poll_id INT NULL,
  image_url VARCHAR(255) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_pqo_question (question_id),
  KEY idx_pqo_next_question (next_question_id),
  KEY idx_pqo_target_poll (target_poll_id),
  CONSTRAINT fk_pqo_question FOREIGN KEY (question_id) REFERENCES poll_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS poll_participations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  poll_id INT NOT NULL,
  participant_id INT NOT NULL,
  current_question_id INT NULL,
  status ENUM('in_progress','completed','terminated') NOT NULL DEFAULT 'in_progress',
  started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_poll_participation (poll_id, participant_id),
  KEY idx_ppart_poll (poll_id),
  KEY idx_ppart_participant (participant_id),
  KEY idx_ppart_current_question (current_question_id),
  CONSTRAINT fk_ppart_poll FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
  CONSTRAINT fk_ppart_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS poll_answers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  poll_id INT NOT NULL,
  participant_id INT NOT NULL,
  question_id INT NOT NULL,
  option_id INT NOT NULL,
  is_draft TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_answer_option (poll_id, participant_id, question_id, option_id),
  KEY idx_answers_question (question_id),
  KEY idx_answers_option (option_id),
  CONSTRAINT fk_answers_poll FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
  CONSTRAINT fk_answers_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
  CONSTRAINT fk_answers_question FOREIGN KEY (question_id) REFERENCES poll_questions(id) ON DELETE CASCADE,
  CONSTRAINT fk_answers_option FOREIGN KEY (option_id) REFERENCES poll_question_options(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS poll_participants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  poll_id INT NOT NULL,
  participant_id INT NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_poll_participant (poll_id, participant_id),
  KEY idx_poll (poll_id),
  KEY idx_participant (participant_id),
  CONSTRAINT fk_pp_poll FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
  CONSTRAINT fk_pp_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS votes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  poll_id INT NOT NULL,
  participant_id INT NOT NULL,
  candidate_id INT NOT NULL,
  ip_hash VARCHAR(64),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_poll_participant (poll_id, participant_id),
  KEY idx_poll_candidate (poll_id, candidate_id),
  KEY fk_vote_participant (participant_id),
  KEY fk_vote_candidate (candidate_id),
  CONSTRAINT fk_vote_poll FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
  CONSTRAINT fk_vote_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
  CONSTRAINT fk_vote_candidate FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(50) NULL,
  entity_id INT NULL,
  description TEXT,
  ip_hash VARCHAR(64),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_admin (admin_id),
  KEY idx_action (action),
  KEY idx_entity (entity_type, entity_id),
  KEY idx_created (created_at),
  CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
