-- xrowextract: scheduled exports, delivery destinations, export history (oracle).
-- Generated from share/db_schema.dba by the kernel schema handler; the extension also creates
-- these tables itself on first use (XrowExtractSchema::ensure()).

CREATE SEQUENCE se_xrowextract_schedule;
CREATE TABLE xrowextract_schedule (
  created INTEGER DEFAULT 0 NOT NULL,
  cron_expr VARCHAR2(100) NOT NULL,
  definition CLOB,
  delta_mode VARCHAR2(10) DEFAULT 'full' NOT NULL,
  destination_ids VARCHAR2(255) NOT NULL,
  enabled INTEGER DEFAULT 1 NOT NULL,
  frequency CLOB,
  id INTEGER NOT NULL,
  kind VARCHAR2(20) NOT NULL,
  last_job_id VARCHAR2(32) NOT NULL,
  last_run INTEGER DEFAULT 0 NOT NULL,
  last_state VARCHAR2(20) NOT NULL,
  last_success INTEGER DEFAULT 0 NOT NULL,
  modified INTEGER DEFAULT 0 NOT NULL,
  name VARCHAR2(150) NOT NULL,
  next_run INTEGER DEFAULT 0 NOT NULL,
  notify CLOB,
  owner_login VARCHAR2(150) NOT NULL,
  retention VARCHAR2(100) NOT NULL,
  PRIMARY KEY ( id )
);
CREATE OR REPLACE TRIGGER xrowextract_schedule_id_tr
BEFORE INSERT ON xrowextract_schedule FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_xrowextract_schedule.nextval INTO :new.id FROM dual;
END;
CREATE INDEX xrowextract_schedule_next ON xrowextract_schedule ( enabled, next_run );

CREATE SEQUENCE se_xrowextract_destination;
CREATE TABLE xrowextract_destination (
  config CLOB,
  created INTEGER DEFAULT 0 NOT NULL,
  dest_type VARCHAR2(20) NOT NULL,
  id INTEGER NOT NULL,
  last_test INTEGER DEFAULT 0 NOT NULL,
  last_test_result CLOB,
  modified INTEGER DEFAULT 0 NOT NULL,
  name VARCHAR2(150) NOT NULL,
  owner_login VARCHAR2(150) NOT NULL,
  secret CLOB,
  PRIMARY KEY ( id )
);
CREATE OR REPLACE TRIGGER xrowextract_destination_id_tr
BEFORE INSERT ON xrowextract_destination FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_xrowextract_destination.nextval INTO :new.id FROM dual;
END;

CREATE SEQUENCE se_xrowextract_history;
CREATE TABLE xrowextract_history (
  byte_size INTEGER DEFAULT 0 NOT NULL,
  checksum VARCHAR2(64) NOT NULL,
  created INTEGER DEFAULT 0 NOT NULL,
  delivery CLOB,
  delivery_state VARCHAR2(20) NOT NULL,
  destinations VARCHAR2(255) NOT NULL,
  ended_at INTEGER DEFAULT 0 NOT NULL,
  error_text CLOB,
  file_name VARCHAR2(255) NOT NULL,
  id INTEGER NOT NULL,
  job_id VARCHAR2(32) NOT NULL,
  kind VARCHAR2(20) NOT NULL,
  output_format VARCHAR2(20) NOT NULL,
  owner_login VARCHAR2(150) NOT NULL,
  preset_ref VARCHAR2(100) NOT NULL,
  row_count INTEGER DEFAULT 0 NOT NULL,
  run_mode VARCHAR2(10) NOT NULL,
  run_state VARCHAR2(20) NOT NULL,
  schedule_id INTEGER DEFAULT 0 NOT NULL,
  started_at INTEGER DEFAULT 0 NOT NULL,
  trigger_type VARCHAR2(20) NOT NULL,
  warning_count INTEGER DEFAULT 0 NOT NULL,
  warnings CLOB,
  what VARCHAR2(255) NOT NULL,
  PRIMARY KEY ( id )
);
CREATE OR REPLACE TRIGGER xrowextract_history_id_tr
BEFORE INSERT ON xrowextract_history FOR EACH ROW WHEN (new.id IS NULL)
BEGIN
  SELECT se_xrowextract_history.nextval INTO :new.id FROM dual;
END;
CREATE INDEX xrowextract_history_created ON xrowextract_history ( created );
CREATE INDEX xrowextract_history_job ON xrowextract_history ( job_id );
CREATE INDEX xrowextract_history_sched ON xrowextract_history ( schedule_id, created );
