#!/bin/sh
# All multi-tenant tests against the local test site (http://127.0.0.1:8082, databases pn_test and pn_iso).
# Needs MariaDB, Apache with tests/deploy-mt.sh's site, and the mocks from tests/up.sh.
set -e
cd "$(dirname "$0")"
../deploy-mt.sh
./reset.sh legacy && python3 scenario.py > /dev/null && echo "scenario: ok"
printf "isolation: "; python3 isolation.py | tail -n +1
./reset.sh legacy && python3 scenario.py > /dev/null
printf "functional: "; python3 functional.py | tail -1
printf "flows: "; python3 flows.py | tail -1
./reset.sh legacy && python3 scenario.py > /dev/null
printf "browser: "; node ui_smoke.js
printf "installer: "; python3 installer.py | tail -1
# Leave the test site as the scenario.
../deploy-mt.sh && ./reset.sh legacy && python3 scenario.py > /dev/null
