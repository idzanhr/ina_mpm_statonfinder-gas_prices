-- INA postaje CMS - MySQL 8 / MariaDB 10.4+ schema. Re-runnable.
-- Columns added after the first release go into Db::UPGRADES, not here.

CREATE TABLE IF NOT EXISTS stations (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(190) NOT NULL,
  address       VARCHAR(255) NOT NULL DEFAULT '',
  city          VARCHAR(120) NOT NULL DEFAULT '',
  postal_code   VARCHAR(16)  NOT NULL DEFAULT '',
  county        VARCHAR(120) NOT NULL DEFAULT '',
  phone         VARCHAR(64)  NOT NULL DEFAULT '',
  lat           VARCHAR(16)  NOT NULL DEFAULT '',
  lng           VARCHAR(16)  NOT NULL DEFAULT '',
  aliases       TEXT         NOT NULL,
  note          VARCHAR(500) NOT NULL DEFAULT '',     -- admin's public note on the card
  ina_url       VARCHAR(255) NOT NULL DEFAULT '',     -- https://www.ina.hr/station/<slug>/
  working_hours TEXT         NOT NULL,                -- JSON [{label, week, sat, sun, hol}]
  fuels         TEXT         NOT NULL,                -- JSON [{name, cat, price, prev, changed_at}]
  services      TEXT         NOT NULL,                -- JSON ["Caffe bar", "Autopraonica", …]
  ina_synced_at VARCHAR(19)  NOT NULL DEFAULT '',     -- last successful read of the ina.hr page
  ina_notice    VARCHAR(500) NOT NULL DEFAULT '',     -- closure wording found on ina.hr
  ina_missing   TINYINT(1)   NOT NULL DEFAULT 0,      -- no longer listed in the ina.hr finder
  status        VARCHAR(16)  NOT NULL DEFAULT 'open', -- open | closed (temporarily)
  status_source VARCHAR(8)   NOT NULL DEFAULT '',     -- admin | ina
  status_note   VARCHAR(255) NOT NULL DEFAULT '',
  closed_from   VARCHAR(10)  NOT NULL DEFAULT '',
  closed_until  VARCHAR(10)  NOT NULL DEFAULT '',
  hidden        TINYINT(1)   NOT NULL DEFAULT 0,      -- not published
  locked        TINYINT(1)   NOT NULL DEFAULT 0,      -- sync keeps name/address/phone/hours as edited
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  KEY stations_ina_url (ina_url)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS price_history (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  station_id  INT UNSIGNED NOT NULL,
  fuel_name   VARCHAR(120) NOT NULL,
  category    VARCHAR(16)  NOT NULL DEFAULT '',
  old_price   VARCHAR(16)  NOT NULL DEFAULT '',
  new_price   VARCHAR(16)  NOT NULL DEFAULT '',
  changed_at  DATETIME     NOT NULL,
  KEY price_history_station (station_id),
  KEY price_history_time (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exactly one administrator: the primary key is pinned to 1.
CREATE TABLE IF NOT EXISTS admin (
  id                   TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  email                VARCHAR(190) NOT NULL,
  password_hash        VARCHAR(255) NOT NULL,
  totp_secret_enc      VARCHAR(255) NULL,
  totp_last_step       BIGINT       NOT NULL DEFAULT 0,
  must_change_password TINYINT(1)   NOT NULL DEFAULT 1,
  session_version      INT UNSIGNED NOT NULL DEFAULT 1,
  updated_at           DATETIME     NOT NULL,
  CONSTRAINT admin_single_row CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recovery_codes (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code_hash VARCHAR(255) NOT NULL,
  used_at   DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip           VARCHAR(45) NOT NULL,
  stage        VARCHAR(16) NOT NULL,
  success      TINYINT(1)  NOT NULL,
  attempted_at DATETIME    NOT NULL,
  KEY login_attempts_ip_time (ip, attempted_at),
  KEY login_attempts_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  action       VARCHAR(32)  NOT NULL,
  station_name VARCHAR(190) NOT NULL DEFAULT '',
  detail       TEXT         NOT NULL,
  ip           VARCHAR(45)  NOT NULL DEFAULT '',
  created_at   DATETIME     NOT NULL,
  KEY audit_log_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
