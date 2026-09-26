#!/usr/bin/env bash
# Sauvegarde de Ngoni Caisse : base « ecaisse » et fichiers envoyés (preuves
# de paiement). Lancée par cron deux fois par jour ; garde 30 jours.
#     bash /var/www/NGONI-PAY-V2/deploy/sauvegarde.sh
#
# Restauration d'une base :
#     gunzip -c FICHIER.sql.gz | docker exec -i backend-mysql-1 mysql -u… -p… ecaisse
set -euo pipefail

APP=/var/www/NGONI-PAY-V2
DEST=/home/ismael/backups/ngoni-caisse
JOURNAL=/var/www/sauvegarde-ngonicaisse.log
DATE=$(date +%Y%m%d-%H%M)
GARDER_JOURS=30

journal() { echo "[$(date '+%F %T')] $*" >> "$JOURNAL"; }
env_app() { grep "^$1=" "$APP/.env" | head -1 | cut -d= -f2- | tr -d '"'; }

mkdir -p "$DEST"

BASE="$DEST/ecaisse-$DATE.sql.gz"
docker exec -e MYSQL_PWD="$(env_app DB_PASSWORD)" backend-mysql-1 \
  mysqldump -u"$(env_app DB_USERNAME)" --single-transaction --no-tablespaces --routines --triggers "$(env_app DB_DATABASE)" \
  | gzip -9 > "$BASE.part"

# Un fichier vide ou tronqué ne doit jamais passer pour une sauvegarde.
gzip -t "$BASE.part"
if [ "$(gunzip -c "$BASE.part" | grep -c 'CREATE TABLE')" -lt 10 ]; then
  journal "ÉCHEC : sauvegarde de la base incomplète"; rm -f "$BASE.part"; exit 1
fi
mv "$BASE.part" "$BASE"

# Fichiers envoyés (preuves, logos…), sans la clé Firebase.
FICHIERS="$DEST/fichiers-$DATE.tar.gz"
docker run --rm -v "$APP/storage/app:/src:ro" -v "$DEST:/dest" alpine \
  tar -czf "/dest/$(basename "$FICHIERS")" --exclude=./firebase -C /src . 2>/dev/null || true

find "$DEST" -type f -mtime +"$GARDER_JOURS" -delete

journal "OK base $(du -h "$BASE" | cut -f1), fichiers $(du -h "$FICHIERS" 2>/dev/null | cut -f1 || echo 0)"
