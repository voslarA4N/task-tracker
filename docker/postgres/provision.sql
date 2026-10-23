-- Idempotentny provisioning roly a databazy (beh ako superuser postgres).
-- Pouzitie: psql -v ON_ERROR_STOP=1 -d postgres -v owner_pw=... -v rw_pw=... -v dbname=... -f provision.sql
-- Hesla prichadzaju iba z premennych (.env), nie su v tomto subore.

SELECT format('CREATE ROLE app_owner LOGIN')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_owner') \gexec

SELECT format('CREATE ROLE app_rw LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_rw') \gexec

ALTER ROLE app_owner PASSWORD :'owner_pw';
ALTER ROLE app_rw PASSWORD :'rw_pw';

-- ICU kolacia sk-SK (spike 01-05: cz_ok=t, ch_after_h=t)
SELECT format('CREATE DATABASE %I OWNER app_owner ENCODING %L LOCALE_PROVIDER icu ICU_LOCALE %L LOCALE %L TEMPLATE template0',
              :'dbname', 'UTF8', 'sk-SK', 'C.UTF-8')
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'dbname') \gexec

REVOKE ALL ON DATABASE :"dbname" FROM PUBLIC;
GRANT CONNECT ON DATABASE :"dbname" TO app_owner, app_rw;
ALTER DATABASE :"dbname" SET timezone TO 'Europe/Bratislava';

\connect :"dbname"

REVOKE CREATE ON SCHEMA public FROM PUBLIC;
ALTER SCHEMA public OWNER TO app_owner;
GRANT USAGE ON SCHEMA public TO app_rw;

-- Fail-closed: app_rw dostane na nove tabulky iba SELECT, INSERT.
-- UPDATE/DELETE len cez grantMutable() v migracii, nikdy TRUNCATE.
ALTER DEFAULT PRIVILEGES FOR ROLE app_owner IN SCHEMA public GRANT SELECT, INSERT ON TABLES TO app_rw;
ALTER DEFAULT PRIVILEGES FOR ROLE app_owner IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO app_rw;
