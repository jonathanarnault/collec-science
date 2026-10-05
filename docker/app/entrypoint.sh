#!/bin/sh
set -e

# first start: create the configuration and the encryption keys
if [ ! -f .env ]; then
    cp docker/app/env .env
    chown www-data:www-data .env
fi
if [ ! -f id_collec ]; then
    openssl genpkey -algorithm rsa -out id_collec -pkeyopt rsa_keygen_bits:2048
    openssl rsa -in id_collec -pubout -out id_collec.pub
    chown www-data:www-data id_collec id_collec.pub
fi

exec docker-php-entrypoint "$@"
