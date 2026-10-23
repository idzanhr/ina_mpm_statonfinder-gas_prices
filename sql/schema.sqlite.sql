-- INA postaje CMS - SQLite schema (local development and tests). Re-runnable.

CREATE TABLE IF NOT EXISTS stations (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  name          TEXT NOT NULL,
  address       TEXT NOT NULL DEFAULT '',
  city          TEXT NOT NULL DEFAULT '',
  postal_code   TEXT NOT NULL DEFAULT '',
  county        TEXT NOT NULL DEFAULT '',
  phone         TEXT NOT NULL DEFAULT '',
  lat           TEXT NOT NULL DEFAULT '',
  lng           TEXT NOT NULL DEFAULT '',
  aliases       TEXT NOT NULL DEFAULT '',
  note          TEXT NOT NULL DEFAULT '',
  ina_url       TEXT NOT NULL DEFAULT '',
  working_hours TEXT NOT NULL DEFAULT '[]',
  fuels         TEXT NOT NULL DEFAULT '[]',
  services      TEXT NOT NULL DEFAULT '[]',
  ina_synced_at TEXT NOT NULL DEFAULT '',
  ina_notice    TEXT NOT NULL DEFAULT '',
  ina_missing   INTEGER NOT NULL DEFAULT 0,
  status        TEXT NOT NULL DEFAULT 'open',
  status_source TEXT NOT NULL DEFAULT '',
  status_note   TEXT NOT NULL DEFAULT '',
  closed_from   TEXT NOT NULL DEFAULT '',
  closed_until  TEXT NOT NULL DEFAULT '',
  hidden        INTEGER NOT NULL DEFAULT 0,
  locked        INTEGER NOT NULL DEFAULT 0,
  created_at    TEXT NOT NULL,
  updated_at    TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS stations_ina_url ON stations (ina_url);

CREATE TABLE IF NOT EXISTS price_history (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  station_id  INTEGER NOT NULL,
  fuel_name   TEXT NOT NULL,
  category    TEXT NOT NULL DEFAULT '',
  old_price   TEXT NOT NULL DEFAULT '',
  new_price   TEXT NOT NULL DEFAULT '',
  changed_at  TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS price_history_station ON price_history (station_id);
CREATE INDEX IF NOT EXISTS price_history_time ON price_history (changed_at);

CREATE TABLE IF NOT EXISTS admin (
  id                   INTEGER PRIMARY KEY DEFAULT 1 CHECK (id = 1),
  email                TEXT NOT NULL,
  password_hash        TEXT NOT NULL,
  totp_secret_enc      TEXT NULL,
  totp_last_step       INTEGER NOT NULL DEFAULT 0,
  must_change_password INTEGER NOT NULL DEFAULT 1,
  session_version      INTEGER NOT NULL DEFAULT 1,
  updated_at           TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS recovery_codes (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  code_hash TEXT NOT NULL,
  used_at   TEXT NULL
);

CREATE TABLE IF NOT EXISTS login_attempts (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  ip           TEXT NOT NULL,
  stage        TEXT NOT NULL,
  success      INTEGER NOT NULL,
  attempted_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS login_attempts_ip_time ON login_attempts (ip, attempted_at);

CREATE TABLE IF NOT EXISTS audit_log (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  action       TEXT NOT NULL,
  station_name TEXT NOT NULL DEFAULT '',
  detail       TEXT NOT NULL DEFAULT '',
  ip           TEXT NOT NULL DEFAULT '',
  created_at   TEXT NOT NULL
);
