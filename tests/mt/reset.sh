#!/bin/sh
# Empty the test database and the test site's data folder. With "legacy", put the 0.7.8 install's data back in it.
mariadb -e "DROP DATABASE IF EXISTS ${DB:-pn_test}; CREATE DATABASE ${DB:-pn_test} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
find /var/www/pnmt/data -mindepth 1 ! -name .htaccess -delete
if [ "$1" = legacy ]; then
  cp -a /home/claude/pn-legacy-data/notes.sqlite /home/claude/pn-legacy-data/connections.php /home/claude/pn-legacy-data/frameio-token.json /home/claude/pn-legacy-data/fingerprints /var/www/pnmt/data/
fi
chown -R www-data:www-data /var/www/pnmt/data
