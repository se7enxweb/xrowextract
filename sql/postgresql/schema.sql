-- xrowextract: scheduled exports, delivery destinations, export history (postgresql).
-- Generated from share/db_schema.dba by the kernel schema handler; the extension also creates
-- these tables itself on first use (XrowExtractSchema::ensure()).

CREATE SEQUENCE IF NOT EXISTS xrowextract_schedule_id_seq
  START 1
  INCREMENT 1
  MAXVALUE 9223372036854775807
  MINVALUE 1
  CACHE 1;
CREATE TABLE IF NOT EXISTS xrowextract_schedule (
  created integer DEFAULT 0 NOT NULL,
  cron_expr character varying(100) DEFAULT ''::character varying NOT NULL,
  definition text,
  delta_mode character varying(10) DEFAULT 'full'::character varying NOT NULL,
  destination_ids character varying(255) DEFAULT ''::character varying NOT NULL,
  enabled integer DEFAULT 1 NOT NULL,
  frequency text,
  id integer DEFAULT nextval('xrowextract_schedule_id_seq'::text) NOT NULL,
  kind character varying(20) DEFAULT ''::character varying NOT NULL,
  last_job_id character varying(32) DEFAULT ''::character varying NOT NULL,
  last_run integer DEFAULT 0 NOT NULL,
  last_state character varying(20) DEFAULT ''::character varying NOT NULL,
  last_success integer DEFAULT 0 NOT NULL,
  modified integer DEFAULT 0 NOT NULL,
  name character varying(150) DEFAULT ''::character varying NOT NULL,
  next_run integer DEFAULT 0 NOT NULL,
  "notify" text,
  owner_login character varying(150) DEFAULT ''::character varying NOT NULL,
  retention character varying(100) DEFAULT ''::character varying NOT NULL
);
CREATE INDEX xrowextract_schedule_next ON xrowextract_schedule USING btree ( enabled, next_run );
ALTER TABLE ONLY xrowextract_schedule ADD CONSTRAINT xrowextract_schedule_pkey PRIMARY KEY ( id );

CREATE SEQUENCE IF NOT EXISTS xrowextract_destination_id_seq
  START 1
  INCREMENT 1
  MAXVALUE 9223372036854775807
  MINVALUE 1
  CACHE 1;
CREATE TABLE IF NOT EXISTS xrowextract_destination (
  config text,
  created integer DEFAULT 0 NOT NULL,
  dest_type character varying(20) DEFAULT ''::character varying NOT NULL,
  id integer DEFAULT nextval('xrowextract_destination_id_seq'::text) NOT NULL,
  last_test integer DEFAULT 0 NOT NULL,
  last_test_result text,
  modified integer DEFAULT 0 NOT NULL,
  name character varying(150) DEFAULT ''::character varying NOT NULL,
  owner_login character varying(150) DEFAULT ''::character varying NOT NULL,
  secret text
);
ALTER TABLE ONLY xrowextract_destination ADD CONSTRAINT xrowextract_destination_pkey PRIMARY KEY ( id );

CREATE SEQUENCE IF NOT EXISTS xrowextract_history_id_seq
  START 1
  INCREMENT 1
  MAXVALUE 9223372036854775807
  MINVALUE 1
  CACHE 1;
CREATE TABLE IF NOT EXISTS xrowextract_history (
  byte_size bigint DEFAULT '0' NOT NULL,
  checksum character varying(64) DEFAULT ''::character varying NOT NULL,
  created integer DEFAULT 0 NOT NULL,
  delivery text,
  delivery_state character varying(20) DEFAULT ''::character varying NOT NULL,
  destinations character varying(255) DEFAULT ''::character varying NOT NULL,
  ended_at integer DEFAULT 0 NOT NULL,
  error_text text,
  file_name character varying(255) DEFAULT ''::character varying NOT NULL,
  id integer DEFAULT nextval('xrowextract_history_id_seq'::text) NOT NULL,
  job_id character varying(32) DEFAULT ''::character varying NOT NULL,
  kind character varying(20) DEFAULT ''::character varying NOT NULL,
  output_format character varying(20) DEFAULT ''::character varying NOT NULL,
  owner_login character varying(150) DEFAULT ''::character varying NOT NULL,
  preset_ref character varying(100) DEFAULT ''::character varying NOT NULL,
  row_count integer DEFAULT 0 NOT NULL,
  run_mode character varying(10) DEFAULT ''::character varying NOT NULL,
  run_state character varying(20) DEFAULT ''::character varying NOT NULL,
  schedule_id integer DEFAULT 0 NOT NULL,
  started_at integer DEFAULT 0 NOT NULL,
  trigger_type character varying(20) DEFAULT ''::character varying NOT NULL,
  warning_count integer DEFAULT 0 NOT NULL,
  warnings text,
  what character varying(255) DEFAULT ''::character varying NOT NULL
);
CREATE INDEX xrowextract_history_created ON xrowextract_history USING btree ( created );
CREATE INDEX xrowextract_history_job ON xrowextract_history USING btree ( job_id );
CREATE INDEX xrowextract_history_sched ON xrowextract_history USING btree ( schedule_id, created );
ALTER TABLE ONLY xrowextract_history ADD CONSTRAINT xrowextract_history_pkey PRIMARY KEY ( id );
