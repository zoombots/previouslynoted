#!/bin/sh
curl -s -o /dev/null http://127.0.0.1:8081/ || { rm -f /run/apache2/apache2.pid /var/run/apache2/apache2.pid; apachectl start 2>/dev/null; }
curl -s -o /dev/null http://127.0.0.1:8099/ || (cd /home/claude/build/mock && nohup php -S 127.0.0.1:8099 router.php > /tmp/claude-0/mock.log 2>&1 &)
nc -z 127.0.0.1 2465 2>/dev/null || (cd /home/claude/build/smtp && nohup python3 server.py > log.txt 2>&1 &)
curl -s -o /dev/null http://127.0.0.1:9222/json/version || (nohup /opt/pw-browsers/chromium-1194/chrome-linux/chrome --headless=new --no-sandbox --remote-debugging-address=127.0.0.1 --remote-debugging-port=9222 --user-data-dir=/tmp/claude-0/cdpprof --window-size=1440,900 about:blank > /tmp/claude-0/cdp.log 2>&1 &)
sleep 2
