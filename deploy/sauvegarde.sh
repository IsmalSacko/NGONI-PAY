#!/usr/bin/env bash
# Sauvegarde de Ngoni Caisse : base « ecaisse » et fichiers envoyés (photos,
# logos, preuves de paiement). Garde 30 jours sur le serveur, et copie chaque
# sauvegarde hors du serveur, sur Google Drive (rclone, distant « gdrive: »),
# où elle est gardée 60 jours : une panne du serveur n'emporte pas tout.
#     bash /var/www/NGONI-PAY-V2/deploy/sauvegarde.sh        # base + fichiers (une fois par jour, 3 h 15)
#     bash /var/www/NGONI-PAY-V2/deploy/sauvegarde.sh base   # la base seule (toutes les heures)
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
if [ "${1:-}" != "base" ]; then
  docker run --rm -v "$APP/storage/app:/src:ro" -v "$DEST:/dest" alpine \
    tar -czf "/dest/$(basename "$FICHIERS")" --exclude=./firebase -C /src . 2>/dev/null || true
fi

find "$DEST" -type f -mtime +"$GARDER_JOURS" -delete

# Copie hors du serveur. Un échec ne fait pas échouer la sauvegarde locale,
# mais se lit en clair dans le journal.
RCLONE=/home/ismael/bin/rclone
DRIVE="gdrive:Sauvegardes Ngoni Caisse"
DISTANT="non configuré"
if [ -x "$RCLONE" ]; then
  if "$RCLONE" copy "$DEST" "$DRIVE" --max-age 20h --log-level ERROR 2>>"$JOURNAL" \
     && "$RCLONE" delete "$DRIVE" --min-age 60d --log-level ERROR 2>>"$JOURNAL"; then
    DISTANT="copié sur Google Drive"
  else
    DISTANT="ÉCHEC de la copie sur Google Drive"
  fi
fi

journal "OK base $(du -h "$BASE" | cut -f1)$( [ "${1:-}" = "base" ] || echo ", fichiers $(du -h "$FICHIERS" 2>/dev/null | cut -f1 || echo 0)") · $DISTANT"
