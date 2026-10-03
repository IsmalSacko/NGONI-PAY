#!/usr/bin/env bash
# Sauvegarde de Ngoni Caisse : base « ecaisse » et fichiers envoyés (photos,
# logos, preuves de paiement). Garde 30 jours sur le serveur (la base toutes
# les heures). Hors du serveur, sur Google Drive (rclone, distant « gdrive: ») :
# UNE base par jour (celle de 3 h), gardée 30 jours, dans « base/ », et les
# fichiers envoyés copiés un par un dans « fichiers/ » — seuls les nouveaux
# partent. Une copie par heure remplissait le Drive de fichiers presque pareils.
#     bash /var/www/NGONI-PAY-V2/deploy/sauvegarde.sh        # base + fichiers (2 fois par jour)
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

# La copie des fichiers envoyés (voir plus bas) est refaite à chaque fois : hors du ménage.
find "$DEST" -path "$DEST/miroir-fichiers" -prune -o -type f -mtime +"$GARDER_JOURS" -delete

# Copie hors du serveur. Un échec ne fait pas échouer la sauvegarde locale,
# mais se lit en clair dans le journal.
RCLONE=/home/ismael/bin/rclone
DRIVE="gdrive:Sauvegardes Ngoni Caisse"
DISTANT="non configuré"
if [ -x "$RCLONE" ] && [ "${1:-}" = "base" ]; then
  DISTANT="gardé sur le serveur seulement (Drive : une fois par jour)"
elif [ -x "$RCLONE" ] && [ "$(date +%H)" != "03" ]; then
  DISTANT="gardé sur le serveur seulement (Drive : la sauvegarde de 3 h)"
elif [ -x "$RCLONE" ]; then
  # Fichiers envoyés : une copie lisible (ils appartiennent à www-data), sans
  # la clé Firebase ni les envois temporaires, puis seuls les nouveaux partent.
  MIROIR="$DEST/miroir-fichiers"
  docker run --rm -v "$APP/storage/app:/src:ro" -v "$DEST:/dest" alpine sh -c \
    "rm -rf /dest/miroir-fichiers && cp -rp /src /dest/miroir-fichiers && rm -rf /dest/miroir-fichiers/firebase /dest/miroir-fichiers/private/livewire-tmp && chmod -R a+rX /dest/miroir-fichiers"
  if "$RCLONE" copyto "$BASE" "$DRIVE/base/$(basename "$BASE")" --log-level ERROR 2>>"$JOURNAL" \
     && "$RCLONE" copy "$MIROIR" "$DRIVE/fichiers" --log-level ERROR 2>>"$JOURNAL" \
     && "$RCLONE" delete "$DRIVE/base" --min-age 30d --log-level ERROR 2>>"$JOURNAL"; then
    DISTANT="copié sur Google Drive"
  else
    DISTANT="ÉCHEC de la copie sur Google Drive"
  fi
fi

journal "OK base $(du -h "$BASE" | cut -f1)$( [ "${1:-}" = "base" ] || echo ", fichiers $(du -h "$FICHIERS" 2>/dev/null | cut -f1 || echo 0)") · $DISTANT"
