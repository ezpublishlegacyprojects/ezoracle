BEWARE OF THE LEOPARD: guidelines for developers

1. remember to commit your fixes to all active branches.
   At the moment (end of 2010) this means 1.8, 2.0 and 2.1

2. always keep in proper order the version in phpinfo.php and ant/ezoracle.properties:
  - in phpinfo.php, version should be current incremented by 0.0.1, followed by -dev
    ex: after 2.0.3 release, it should be 2.0.4-dev
  - in ant/ezoracle.properties, version should be current incremented by 0.0.1
    ex: after 2.0.3 release, it should be 2.0.4

3. when changing dbschema.ini.append.php to add eg. a new column to the list of not-null ones, remember to:
  - also update the file bin/php/mysql2oracle-schema.php (adding the same column)
  - add an UPDATE statement to change that col in update/database/ezoracle

