-- Development only: runs once, when the database volume is first created.
-- Pest uses this database; phpunit.xml forces DB_DATABASE=retail_test.
CREATE DATABASE retail_test OWNER retail;
