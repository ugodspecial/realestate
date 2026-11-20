#!/bin/sh
# Stands in for sendmail when tests/mail.php is run as
#   php -d sendmail_path="sh /full/path/tests/fixtures/mail-capture.sh -t -i" tests/mail.php
# PHP's mail() pipes the finished message to its mail program. This keeps that message, and the
# arguments PHP passed, where the test can read exactly what a real mail program would have been given.
dir="$(cd "$(dirname "$0")" && pwd)/../../storage/logs"
mkdir -p "$dir"
cat > "$dir/mail-capture.eml"
printf '%s\n' "$*" > "$dir/mail-capture.args"
