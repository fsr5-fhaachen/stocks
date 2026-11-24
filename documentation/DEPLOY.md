# Dev-Instanz über GitHub Actions

Die Dev-Instanz läuft unter **https://stocks-dev.proxy.sntg.eu**. Jeder Push auf `dev` baut das Image und spielt es ein. Der Workflow steht in `.github/workflows/deploy-dev.yml`.

```
GitHub (push auf dev) ──▶ build:  GitHub-Runner, docker build, push nach ghcr.io/fsr5-fhaachen/stocks
                      └─▶ deploy: self-hosted Runner auf dem Dev-Server, docker compose up in /opt/stocks (Projekt "stocks")
Reverse Proxy (anderer Host) ──NetBird──▶ Dev-Server:8000 (web)
```

- Der Runner läuft **auf dem Dev-Server** und verbindet sich nur ausgehend mit GitHub. Der Server braucht keinen Zugang von außen.
- Jedes Image trägt den Commit (`:<sha>`), dazu `:dev`. **Zurückrollen:** In GitHub unter *Actions › Deploy dev* einen älteren Lauf öffnen und *Re-run jobs* wählen. Er setzt genau das Image seines Commits.
- **Kein automatischer Neustart:** Schlägt ein Lauf fehl, bleibt er fehlgeschlagen. Kein Workflow hat einen Zeitplan, der baut, nur `docker-cleanup.yaml` räumt nachts alte Images auf. Neu starten nur von Hand (*Re-run jobs* oder *Run workflow*).
- Ein neuer Push auf `dev` bricht einen noch laufenden Build ab. Ein laufender Deploy wird nie abgebrochen, der nächste wartet.
- Migrationen und `stocks:sync` laufen beim Start von `web` wie in jeder Instanz ([SETUP.md](SETUP.md)).
- `deploy/dev/docker-compose.override.yaml` schließt die Ports von MariaDB und MinIO. Von außen ist nur `web` auf Port 8000 erreichbar.
- Releases baut `docker-release.yml`: Tag des Releases, `:latest` nur für das neueste Release, das kein Pre-Release ist.

## Einmalig einrichten

### 1. Runner auf dem Dev-Server

Voraussetzungen: Docker mit Compose **2.24 oder neuer** (`docker compose version`), NetBird verbunden.

```bash
# eigener Benutzer für den Runner; darf Docker benutzen und ins Deploy-Verzeichnis schreiben
sudo useradd -m -s /bin/bash github-runner
sudo usermod -aG docker github-runner
sudo mkdir -p /opt/stocks
sudo chown -R github-runner: /opt/stocks
```

In GitHub unter *Organisation fsr5-fhaachen › Settings › Actions › Runner groups* eine Gruppe `stocks-dev` anlegen:

- *Repository access*: nur `fsr5-fhaachen/stocks`
- **„Allow public repositories“** einschalten (sonst nimmt die Gruppe kein öffentliches Repository an)
- *Workflow access*: **Selected workflows**, nur `fsr5-fhaachen/stocks/.github/workflows/deploy-dev.yml@refs/heads/dev`

Damit führt der Runner nur den Deploy von `dev` aus. Ein Pull Request aus einem Fork kann ihn nicht benutzen, auch wenn er die Workflows ändert. Das ist wichtig, weil das Repository öffentlich ist.

Dann unter *Settings › Actions › Runners › New runner* (Linux) die angezeigten Befehle als `github-runner` ausführen. Beim `config.sh`:

- Runner-Gruppe `stocks-dev`, Label `stocks-dev`

Danach als Dienst einrichten:

```bash
cd ~github-runner/actions-runner
sudo ./svc.sh install github-runner
sudo ./svc.sh start
```

### 2. Umgebung als Secret

Im Repository unter *Settings › Environments* die Umgebung `dev` anlegen:

- *Deployment branches*: **Selected branches**, nur `dev`
- Secret `STOCKS_DEV_ENV` mit dem Inhalt wie die `.env` aus [SETUP.md](SETUP.md), mit diesen Werten:

```env
APP_KEY=base64:...                       # einmal erzeugen und nie ändern (Links der Verleihseiten)
APP_URL=https://stocks-dev.proxy.sntg.eu
APP_ENV=production
SETUP_TOKEN=...
DB_PASSWORD=...
REDIS_PASSWORD=...
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
MAIL_FROM_ADDRESS=...
WEB_PORT=8000
VAPID_PUBLIC_KEY=...                     # für den Push-Test, siehe NOTIFICATIONS.md
VAPID_PRIVATE_KEY=...
```

`STOCKS_IMAGE` gehört nicht hinein, das setzt der Workflow. Er schreibt die Datei bei jedem Deploy nach `/opt/stocks/.env`. Änderungen also immer im Secret machen und danach den Deploy neu starten.

Das Image holt der Deploy mit dem Token des Laufs aus `ghcr.io`. Das Paket muss dem Repository zugeordnet sein (*Package settings › Manage Actions access*: `stocks` mit Rolle *Write*), sonst schlägt der Push im Build fehl.

### 3. Reverse Proxy

Auf dem Proxy-Host `stocks-dev.proxy.sntg.eu` auf `http://<NetBird-IP des Dev-Servers>:8000` leiten, mit TLS am Proxy. `APP_FORCE_HTTPS` (Vorgabe `true`) sorgt dafür, dass Stocks alle Links mit https erzeugt. Ohne HTTPS gibt es keinen Push.

`TRUSTED_PROXIES` (Vorgabe `*`): In Produktion die Adresse des Proxys eintragen (kommagetrennt bei mehreren) oder den Port nur an die Schnittstelle des Proxys binden (`WEB_PORT=10.x.x.x:8000`). Mit `*` kann jeder, der Port 8000 direkt erreicht, seine Adresse fälschen und die Sperren nach Adresse umgehen. Auf dev ist Port 8000 nur über NetBird erreichbar.

### 4. Erster Start

Auf `dev` pushen oder unter *Actions › Deploy dev › Run workflow* für `dev` starten. Danach einrichten, entweder im Browser unter `/setup` oder so:

```bash
cd /opt/stocks
sudo -u github-runner docker compose exec web php artisan stocks:install --name="..." --short-name="..." \
  --admin-firstname=... --admin-lastname=... --admin-email=...
```

### Umstellung von GitLab

Erledigt am 24.11.2026: GitLab-Runner auf dem Server entfernt. Die Dev-Instanz lief vorher von Hand gestartet in `/opt/stocks` (Compose-Projekt `stocks`, Volumes `stocks_mariadb` und `stocks_minio_data`). Der Deploy nutzt bewusst denselben Ordner und Projektnamen, so bleiben Datenbank, Fotos und `APP_KEY` ohne Umzug erhalten. Die Sicherung vor der Umstellung liegt in `/root/stocks-backup` (`.env`, `db.sql`, `minio.tar.gz`).

## Im Betrieb

| Aufgabe | Befehl in `/opt/stocks` |
|---|---|
| Logs | `docker compose logs -f web queue scheduler` |
| Artisan | `docker compose exec web php artisan …` |
| Demo-Daten (löscht alles) | `docker compose exec web php artisan migrate:fresh --seed --force` |
| Datenbank sichern | `docker compose exec mariadb sh -c 'mariadb-dump -u stocks -p"$MARIADB_PASSWORD" stocks' > backup.sql` |
| MariaDB, Redis, MinIO aktualisieren | `docker compose pull mariadb redis minio && docker compose up -d` (vorher sichern) |

Jeder Deploy legt ein Image mit dem Commit als Tag ab. Der Deploy-Job löscht auf dem Server Images, die älter als eine Woche und nicht in Benutzung sind (`docker image prune -af --filter "until=168h"`). In `ghcr.io` räumt `docker-cleanup.yaml` nachts Images ohne Tag auf.

Der Deploy holt nur das eigene Image. Die Fremd-Images werden bewusst nur von Hand aktualisiert, damit ein Deploy nie unbemerkt die Datenbank-Version wechselt.

Mit `APP_ENV=production` fragt Laravel vor `migrate:fresh` nach, deshalb steht oben `--force`.
