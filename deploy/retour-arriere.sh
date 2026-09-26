#!/usr/bin/env bash
# Retour arrière : la stack publique sert à nouveau Ngoni Pay (v1), telle
# qu'avant deploy/bascule.sh. La base Ngoni Pay n'a pas été modifiée par la
# bascule ; seules les ventes faites dans e-caisse depuis restent dans la
# base « ecaisse ».
#     bash /var/www/NGONI-PAY-V2/deploy/retour-arriere.sh
set -euo pipefail

STACK=/var/www/_stacks/ngonipay
DEPLOY=/var/www/auto-deploy-ngonipay.sh

[ -f "$STACK/docker-compose.yml.avant-bascule" ] || { echo "Aucune bascule à annuler"; exit 1; }

cp "$STACK/docker-compose.yml.avant-bascule" "$STACK/docker-compose.yml"
rm "$STACK/docker-compose.yml.avant-bascule"
[ -f "$DEPLOY.avant-bascule" ] && mv "$DEPLOY.avant-bascule" "$DEPLOY"

(cd "$STACK" && docker compose up -d --force-recreate)
sleep 5
docker exec ngonipay-phpfpm php artisan up || true

code=$(curl -s -o /dev/null -w '%{http_code}' -m 15 http://127.0.0.1:8084/up || echo 000)
echo "/up → $code"
echo "Ngoni Pay (v1) est de nouveau en ligne."
