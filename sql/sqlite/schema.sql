-- xrowextract: scheduled exports, delivery destinations, export history (sqlite).
-- Generated from share/db_schema.dba by the kernel schema handler; the extension also creates
-- these tables itself on first use (XrowExtractSchema::ensure()).

CREATE TABLE xrowextract_schedule (
  created INTEGER(11) NOT NULL DEFAULT '0',
  cron_expr varchar(100) NOT NULL DEFAULT '',
  definition longtext,
  delta_mode varchar(10) NOT NULL DEFAULT 'full',
  destination_ids varchar(255) NOT NULL DEFAULT '',
  enabled INTEGER(11) NOT NULL DEFAULT '1',
  frequency longtext,
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  kind varchar(20) NOT NULL DEFAULT '',
  last_job_id varchar(32) NOT NULL DEFAULT '',
  last_run INTEGER(11) NOT NULL DEFAULT '0',
  last_state varchar(20) NOT NULL DEFAULT '',
  last_success INTEGER(11) NOT NULL DEFAULT '0',
  modified INTEGER(11) NOT NULL DEFAULT '0',
  name varchar(150) NOT NULL DEFAULT '',
  next_run INTEGER(11) NOT NULL DEFAULT '0',
  notify longtext,
  owner_login varchar(150) NOT NULL DEFAULT '',
  retention varchar(100) NOT NULL DEFAULT ''
);
CREATE  INDEX xrowextract_schedule_next ON xrowextract_schedule  ( enabled, next_run );

CREATE TABLE xrowextract_destination (
  config longtext,
  created INTEGER(11) NOT NULL DEFAULT '0',
  dest_type varchar(20) NOT NULL DEFAULT '',
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  last_test INTEGER(11) NOT NULL DEFAULT '0',
  last_test_result longtext,
  modified INTEGER(11) NOT NULL DEFAULT '0',
  name varchar(150) NOT NULL DEFAULT '',
  owner_login varchar(150) NOT NULL DEFAULT '',
  secret longtext
);

CREATE TABLE xrowextract_history (
  byte_size bigint(20) NOT NULL DEFAULT '0',
  checksum varchar(64) NOT NULL DEFAULT '',
  created INTEGER(11) NOT NULL DEFAULT '0',
  delivery longtext,
  delivery_state varchar(20) NOT NULL DEFAULT '',
  destinations varchar(255) NOT NULL DEFAULT '',
  ended_at INTEGER(11) NOT NULL DEFAULT '0',
  error_text longtext,
  file_name varchar(255) NOT NULL DEFAULT '',
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  job_id varchar(32) NOT NULL DEFAULT '',
  kind varchar(20) NOT NULL DEFAULT '',
  output_format varchar(20) NOT NULL DEFAULT '',
  owner_login varchar(150) NOT NULL DEFAULT '',
  preset_ref varchar(100) NOT NULL DEFAULT '',
  row_count INTEGER(11) NOT NULL DEFAULT '0',
  run_mode varchar(10) NOT NULL DEFAULT '',
  run_state varchar(20) NOT NULL DEFAULT '',
  schedule_id INTEGER(11) NOT NULL DEFAULT '0',
  started_at INTEGER(11) NOT NULL DEFAULT '0',
  trigger_type varchar(20) NOT NULL DEFAULT '',
  warning_count INTEGER(11) NOT NULL DEFAULT '0',
  warnings longtext,
  what varchar(255) NOT NULL DEFAULT ''
);
CREATE  INDEX xrowextract_history_created ON xrowextract_history  ( created );
CREATE  INDEX xrowextract_history_job ON xrowextract_history  ( job_id );
CREATE  INDEX xrowextract_history_sched ON xrowextract_history  ( schedule_id, created );
