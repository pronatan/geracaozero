#!/bin/bash
# Se nginx estiver ativo, habilita try_files com .html (URLs limpas).
set -e
CONF="/etc/nginx/conf.d/elasticbeanstalk/php.conf"
if [ -f "$CONF" ] && command -v nginx >/dev/null 2>&1; then
  if grep -q 'try_files \$uri \$uri/ /index.php' "$CONF"; then
    sed -i 's|try_files \$uri \$uri/ /index.php?\$query_string;|try_files \$uri \$uri.html \$uri/ /index.php?\$query_string;|' "$CONF"
  fi
  nginx -t && systemctl reload nginx || true
fi
