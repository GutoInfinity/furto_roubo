#!/bin/sh
# Prepara o banco (cria tabelas e o administrador se ainda não existirem) e inicia o Apache.
set -e
mkdir -p /var/www/html/storage/imports /var/www/html/storage/templates /var/www/html/storage/logs
chown -R www-data:www-data /var/www/html/storage

if [ -n "$DB_NAME" ]; then
  tries=0
  until php /var/www/html/scripts/install.php; do
    tries=$((tries+1))
    if [ "$tries" -ge 20 ]; then echo "Banco indisponível: o sistema inicia mesmo assim, confira DB_HOST/DB_USER/DB_PASS."; break; fi
    echo "Aguardando o banco de dados ($tries/20)..."; sleep 3
  done
fi

exec "$@"
