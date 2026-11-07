@echo off
echo Running MariaDB schema migration...
mysql -u root -p inventory < db\schema.sql
echo Schema.sql migration complete.
pause