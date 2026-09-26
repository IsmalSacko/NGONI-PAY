#!/usr/bin/env bash
# Bascule de Ngoni Pay vers e-caisse (même domaine : ngonipay.ismael-dev.com).
#
# À lancer sur le serveur, une seule fois, au moment où la version 2.0.0 de
# l'application est disponible sur le Play Store :
#     bash /var/www/NGONI-PAY-V2/deploy/bascule.sh
#
# Étapes : maintenance de l'ancienne API → sauvegarde complète de la base
# Ngoni Pay → base e-caisse remise à zéro et import des données du moment →
# la stack publique (port 8084) sert désormais e-caisse → contrôles.
# Retour arrière : deploy/retour-arriere.sh.
set -euo pipefail

V1=/var/www/NGONI-PAY
V2=/var/www/NGONI-PAY-V2
STACK=/var/www/_stacks/ngonipay
STACK_V2=/var/www/_stacks/ngonipay-v2
DEPLOY=/var/www/auto-deploy-ngonipay.sh
DATE=$(date +%Y%m%d-%H%M)
SAUVEGARDES=/home/ismael/backups

etape() { printf '\n==> %s\n' "$*"; }
env_v1() { grep "^$1=" "$V1/.env" | head -1 | cut -d= -f2- | tr -d '"'; }

etape "Vérifications"
[ -f "$V2/.env" ] || { echo "Il manque $V2/.env"; exit 1; }
docker ps --format '{{.Names}}' | grep -q '^ngonipay2-phpfpm$' || { echo "La préproduction (ngonipay2-phpfpm) ne tourne pas"; exit 1; }
[ -f "$STACK/docker-compose.yml.avant-bascule" ] && { echo "Bascule déjà faite ($STACK/docker-compose.yml.avant-bascule existe)"; exit 1; }
# package-lock.json réécrit par le npm install de la mise en place : on
# revient à la version du dépôt, sinon le pull --ff-only refuse.
git -C "$V2" checkout -- package-lock.json
git -C "$V2" pull -q --ff-only origin v2
mkdir -p "$SAUVEGARDES"

etape "Ancienne API en maintenance"
docker exec ngonipay-phpfpm php artisan down --retry=60 || true

etape "Sauvegarde complète de la base Ngoni Pay"
docker exec -e MYSQL_PWD="$(env_v1 DB_PASSWORD)" backend-mysql-1 \
  mysqldump -u"$(env_v1 DB_USERNAME)" --single-transaction --no-tablespaces --routines ngonipay \
  | gzip > "$SAUVEGARDES/ngonipay-avant-bascule-$DATE.sql.gz"
ls -la "$SAUVEGARDES/ngonipay-avant-bascule-$DATE.sql.gz"

etape "Base e-caisse remise à zéro (données d'essai de la préproduction) et import"
docker exec ngonipay2-phpfpm php artisan migrate:fresh --force
docker exec ngonipay2-phpfpm php artisan ecaisse:importer-ngonipay
docker exec ngonipay2-phpfpm php artisan ecaisse:sync-role-permissions

etape "La stack publique sert e-caisse"
cp "$STACK/docker-compose.yml" "$STACK/docker-compose.yml.avant-bascule"
sed -i \
  -e 's#image: laravel-php:8.2#image: laravel-php:8.4#' \
  -e "s#$V1:/var/www/html#$V2:/var/www/html#g" \
  "$STACK/docker-compose.yml"
(cd "$STACK_V2" && docker compose down)
(cd "$STACK" && docker compose up -d --force-recreate)

etape "Déploiement automatique : branche v2 dans $V2"
cp "$DEPLOY" "$DEPLOY.avant-bascule"
# package-lock.json est suivi dans ce dépôt : npm ci ne le réécrit pas, et
# le pull suivant n'est pas bloqué.
sed -i -e "s#^DIR=.*#DIR=$V2#" -e 's#^BRANCH=.*#BRANCH=v2#' \
  -e 's#npm install --no-audit --no-fund#npm ci --no-audit --no-fund#' "$DEPLOY"
grep -E '^(DIR|BRANCH)=' "$DEPLOY"

etape "Contrôles"
sleep 5
docker exec ngonipay-phpfpm php artisan config:clear >/dev/null
for chemin in /up /api/app-version /api/plans /api/pays /connexion; do
  code=$(curl -s -o /dev/null -w '%{http_code}' -m 15 "http://127.0.0.1:8084$chemin" || echo 000)
  echo "$chemin → $code"
  [ "$code" = "200" ] || { echo "Contrôle en échec : lancer deploy/retour-arriere.sh si besoin"; exit 1; }
done
curl -s http://127.0.0.1:8084/api/app-version; echo

etape "Bascule terminée. Ngoni Pay est désormais e-caisse."
