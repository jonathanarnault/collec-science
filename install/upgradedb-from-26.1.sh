#!/bin/bash
ROOT=install/pgsql
psql $ADDRESS -f "$ROOT/col_alter_26.1-26.2.sql"
psql $ADDRESS -f "$ROOT/col_alter_26.2-26.3.sql"
