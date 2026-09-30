-- xrowextract: scheduled exports, delivery destinations, export history (mysql).
-- Generated from share/db_schema.dba by the kernel schema handler; the extension also creates
-- these tables itself on first use (XrowExtractSchema::ensure()).

CREATE TABLE xrowextract_schedule (
  created int(11) NOT NULL DEFAULT '0',
  cron_expr varchar(100) NOT NULL DEFAULT '',
  definition longtext,
  delta_mode varchar(10) NOT NULL DEFAULT 'full',
  destination_ids varchar(255) NOT NULL DEFAULT '',
  enabled int(11) NOT NULL DEFAULT '1',
  frequency longtext,
  id int(11) NOT NULL AUTO_INCREMENT,
  kind varchar(20) NOT NULL DEFAULT '',
  last_job_id varchar(32) NOT NULL DEFAULT '',
  last_run int(11) NOT NULL DEFAULT '0',
  last_state varchar(20) NOT NULL DEFAULT '',
  last_success int(11) NOT NULL DEFAULT '0',
  modified int(11) NOT NULL DEFAULT '0',
  name varchar(150) NOT NULL DEFAULT '',
  next_run int(11) NOT NULL DEFAULT '0',
  notify longtext,
  owner_login varchar(150) NOT NULL DEFAULT '',
  retention varchar(100) NOT NULL DEFAULT '',
  PRIMARY KEY ( id ),
  KEY xrowextract_schedule_next ( enabled, next_run )
);

CREATE TABLE xrowextract_destination (
  config longtext,
  created int(11) NOT NULL DEFAULT '0',
  dest_type varchar(20) NOT NULL DEFAULT '',
  id int(11) NOT NULL AUTO_INCREMENT,
  last_test int(11) NOT NULL DEFAULT '0',
  last_test_result longtext,
  modified int(11) NOT NULL DEFAULT '0',
  name varchar(150) NOT NULL DEFAULT '',
  owner_login varchar(150) NOT NULL DEFAULT '',
  secret longtext,
  PRIMARY KEY ( id )
);

CREATE TABLE xrowextract_history (
  byte_size bigint(20) NOT NULL DEFAULT '0',
  checksum varchar(64) NOT NULL DEFAULT '',
  created int(11) NOT NULL DEFAULT '0',
  delivery longtext,
  delivery_state varchar(20) NOT NULL DEFAULT '',
  destinations varchar(255) NOT NULL DEFAULT '',
  ended_at int(11) NOT NULL DEFAULT '0',
  error_text longtext,
  file_name varchar(255) NOT NULL DEFAULT '',
  id int(11) NOT NULL AUTO_INCREMENT,
  job_id varchar(32) NOT NULL DEFAULT '',
  kind varchar(20) NOT NULL DEFAULT '',
  output_format varchar(20) NOT NULL DEFAULT '',
  owner_login varchar(150) NOT NULL DEFAULT '',
  preset_ref varchar(100) NOT NULL DEFAULT '',
  row_count int(11) NOT NULL DEFAULT '0',
  run_mode varchar(10) NOT NULL DEFAULT '',
  run_state varchar(20) NOT NULL DEFAULT '',
  schedule_id int(11) NOT NULL DEFAULT '0',
  started_at int(11) NOT NULL DEFAULT '0',
  trigger_type varchar(20) NOT NULL DEFAULT '',
  warning_count int(11) NOT NULL DEFAULT '0',
  warnings longtext,
  what varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY ( id ),
  KEY xrowextract_history_created ( created ),
  KEY xrowextract_history_job ( job_id ),
  KEY xrowextract_history_sched ( schedule_id, created )
);
