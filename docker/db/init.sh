#!/bin/sh
# Creation of the database, executed on the first start of the db container
# Same as install/init_by_psql.sql, which cannot be used as is:
# the init server only listens on the unix socket, not on localhost
set -e

psql -v ON_ERROR_STOP=1 <<'SQL'
CREATE USER collec WITH LOGIN NOSUPERUSER INHERIT NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD 'collecPassword';
CREATE DATABASE collec OWNER collec;
\c collec
CREATE EXTENSION IF NOT EXISTS postgis SCHEMA public;
CREATE EXTENSION IF NOT EXISTS pgcrypto SCHEMA public;
CREATE EXTENSION IF NOT EXISTS pg_trgm SCHEMA pg_catalog;
SQL

psql -v ON_ERROR_STOP=1 -U collec -d collec -f /install/pgsql/collec_create.sql
