-- V10 FINAL Questionnaire + Branching + Session Limit
-- Compatible with an existing multi-polling database.
-- Run ONCE in phpMyAdmin. It is intentionally idempotent.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1) Polling type questionnaire
ALTER TABLE polls
  MODIFY COLUMN poll_type ENUM('single_choice','multiple_choice','yes_no','image_choice','questionnaire') NOT NULL DEFAULT 'single_choice';

-- 2) Session limit
ALTER TABLE poll_settings
  ADD COLUMN IF NOT EXISTS max_questions INT NOT NULL DEFAULT 0 AFTER randomize_candidates;

-- 3) Questionnaire tables. These CREATE statements are included because an older
-- database may have received the multi-polling migration but not the questionnaire tables.
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
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_answer_option (poll_id, participant_id, question_id, option_id),
  KEY idx_answers_question (question_id),
  KEY idx_answers_option (option_id),
  CONSTRAINT fk_answers_poll FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
  CONSTRAINT fk_answers_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
  CONSTRAINT fk_answers_question FOREIGN KEY (question_id) REFERENCES poll_questions(id) ON DELETE CASCADE,
  CONSTRAINT fk_answers_option FOREIGN KEY (option_id) REFERENCES poll_question_options(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add optional image for each questionnaire question.
ALTER TABLE poll_questions ADD COLUMN IF NOT EXISTS image_url VARCHAR(255) NULL AFTER question_text;

-- 4) Add branching/session columns to tables that may already exist.
ALTER TABLE poll_question_options
  ADD COLUMN IF NOT EXISTS next_question_id INT NULL AFTER ends_poll;
ALTER TABLE poll_question_options
  ADD COLUMN IF NOT EXISTS target_poll_id INT NULL AFTER next_question_id;
ALTER TABLE poll_question_options
  ADD COLUMN IF NOT EXISTS image_url VARCHAR(255) NULL AFTER target_poll_id;
ALTER TABLE poll_question_options
  ADD INDEX IF NOT EXISTS idx_pqo_next_question (next_question_id);
ALTER TABLE poll_question_options
  ADD INDEX IF NOT EXISTS idx_pqo_target_poll (target_poll_id);

ALTER TABLE poll_participations
  ADD COLUMN IF NOT EXISTS current_question_id INT NULL AFTER participant_id;
ALTER TABLE poll_participations
  ADD INDEX IF NOT EXISTS idx_ppart_current_question (current_question_id);

SET FOREIGN_KEY_CHECKS = 1;

-- NOTE: no foreign key is created from next_question_id intentionally.
-- Application code validates that the target belongs to the same poll.


ALTER TABLE poll_answers
  ADD COLUMN IF NOT EXISTS is_draft TINYINT(1) NOT NULL DEFAULT 0 AFTER option_id;
