# Stocks

Inventar- und Verleihsoftware für Fachschaften: was besitzen wir, wo liegt es, wer hat es gerade.

Stocks baut auf der Struktur von [Portals](https://github.com/fsr5-fhaachen/portals) auf: Laravel 13, PHP 8.5, Inertia 3, Vue 3 mit TypeScript, Tailwind und Octane mit Swoole.

> **Stand:** Phase 5 mit Review. Kern, Inventar, Etiketten, Verleih mit Verleihseite für Entleiher, Benachrichtigungen per Mail und Push sowie das Modul Kiosk sind fertig. Als Nächstes folgt Phase 6 (öffentlicher Katalog und Anfragen).

## Inhalt

- [Entwicklungsumgebung aufsetzen](#entwicklungsumgebung-aufsetzen)
  - [Welcher Weg?](#welcher-weg)
  - [Weg A: Laravel Sail (empfohlen)](#weg-a-laravel-sail-empfohlen)
  - [Weg B: DevContainer in VS Code](#weg-b-devcontainer-in-vs-code)
  - [Weg C: Nativ auf dem Rechner](#weg-c-nativ-auf-dem-rechner)
- [Nach dem ersten Start](#nach-dem-ersten-start)
- [Vor dem Commit](#vor-dem-commit)
- [Häufige Probleme](#häufige-probleme)
- [Dokumentation](#dokumentation)
- [Lizenz](#lizenz)

## Entwicklungsumgebung aufsetzen

### Welcher Weg?

|                         | A: Sail (empfohlen)       | B: DevContainer                | C: Nativ                                   |
| ----------------------- | ------------------------- | ------------------------------ | ------------------------------------------ |
| Was läuft wo            | alles in Docker           | alles in Docker, VS Code darin | PHP und Node auf dem Rechner               |
| Auf dem Rechner nötig   | Docker, Git               | Docker, Git, VS Code           | PHP 8.5, Composer, Node 24, Git            |
| Datenbank, Redis, MinIO | starten mit               | starten mit                    | selbst, oder nur diese Dienste über Docker |
| Queue und Scheduler     | laufen mit                | laufen mit                     | von Hand starten                           |
| Windows                 | nur über WSL2             | nur über WSL2                  | direkt                                     |
| Gut für                 | gleiche Umgebung für alle | wer ohnehin VS Code nutzt      | schnelle Tests, IDE mit lokalem PHP        |

Alle Wege enden gleich: Stocks läuft im Browser, mit Demo-Daten und den [Demo-Konten](#nach-dem-ersten-start).

### Weg A: Laravel Sail (empfohlen)

Sail startet Stocks mit allen Diensten in Docker: App, MariaDB 11, Redis, MinIO (S3 für Fotos), Queue und Scheduler (`docker-compose.sail.yaml`). Auf dem Rechner braucht es nur Docker und Git.

**Voraussetzungen**

- macOS und Linux: [Docker Desktop](https://www.docker.com/products/docker-desktop/) oder Docker Engine mit Compose.
- Windows: Docker Desktop mit **WSL2** (`wsl --install`, dann in Docker Desktop unter Settings › Resources › WSL Integration die Distribution einschalten). Das Repository **im Linux-Dateisystem** von WSL klonen (z. B. `~/stocks`), nicht unter `C:\` oder `/mnt/c`: Dort ist Docker sehr langsam. Alle Befehle unten in der WSL-Konsole ausführen.

> [!IMPORTANT]
> Sail ist ein Bash-Skript und läuft **nicht in PowerShell oder cmd**, auch `alias sail=…` gibt es dort nicht. Liegt der Klon auf einem Windows-Laufwerk (`C:\`, `P:\`, Netzlaufwerk), scheitert `./vendor/bin/sail` auch über WSL, weil WSL solche Pfade nicht immer übersetzen kann; und selbst mit `docker compose` von Hand wäre die App dort sehr langsam, weil der Container jede Datei über die Freigabe von Docker Desktop liest. Wer unter Windows ohne WSL arbeiten will oder den Klon auf einem Windows-Laufwerk hat, nimmt [Weg C](#weg-c-nativ-auf-dem-rechner); Datenbank, Redis und MinIO lassen sich dabei trotzdem über Docker starten (Variante C1, geht auch aus PowerShell).

**1. Klonen**

```bash
git clone https://github.com/fsr5-fhaachen/stocks.git
cd stocks
```

**2. `.env` anlegen und auf die Dienste von Sail zeigen lassen**

```bash
cp .env.example .env
```

In der `.env` diese Zeilen ändern (die Dienste heißen im Docker-Netz `mariadb` und `redis`, MinIO steht schon richtig):

```env
DB_HOST=mariadb
REDIS_HOST=redis
```

Läuft auf dem Rechner schon etwas auf Port 80, 3306, 6379, 9000 oder 5173, hier ausweichen: `APP_PORT=8080` (dann auch `APP_URL=http://localhost:8080`), `FORWARD_DB_PORT=3307` usw.

**3. PHP-Pakete installieren, ohne PHP auf dem Rechner**

Sail selbst kommt erst mit `composer install`. Deshalb einmal über einen Composer-Container:

```bash
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/app" -w /app composer:2 install --ignore-platform-reqs
```

**4. Starten**

```bash
./vendor/bin/sail up -d
```

Der erste Start baut das Image und dauert einige Minuten. Tipp: `alias sail='./vendor/bin/sail'` in die `~/.bashrc` bzw. `~/.zshrc` (nicht PowerShell), dann reicht `sail …`.

**5. Schlüssel, Datenbank und Demo-Daten**

```bash
sail artisan key:generate
sail artisan migrate:fresh --seed
```

**6. Oberfläche**

```bash
sail npm install
sail npm run dev
```

`npm run dev` läuft im Vordergrund und baut die Oberfläche bei jeder Änderung neu; das Terminal offen lassen.

**7. Formatier-Hook**

`npm install` hat den Hook schon eingeschaltet (`core.hooksPath` zeigt auf `.githooks`). Er formatiert vor jedem Commit (siehe [Vor dem Commit](#vor-dem-commit)), läuft aber auf dem Rechner, nicht im Container: Dort braucht er Node (und für Pint PHP). Ohne Node bricht er bei JS- und Vue-Dateien mit einem Hinweis ab.

**Fertig:** <http://localhost> öffnen (bzw. den Port aus `APP_PORT`).

**Im Alltag**

| Aufgabe                   | Befehl                                            |
| ------------------------- | ------------------------------------------------- |
| Starten / Stoppen         | `sail up -d` / `sail stop`                        |
| Oberfläche im Dev-Modus   | `sail npm run dev`                                |
| Artisan, Composer, npm    | `sail artisan …`, `sail composer …`, `sail npm …` |
| Tests                     | `sail artisan test --compact`                     |
| Logs                      | `sail logs -f laravel.test queue scheduler`       |
| Shell im Container        | `sail shell`                                      |
| Alles inkl. Daten löschen | `sail down -v`                                    |

### Weg B: DevContainer in VS Code

Der DevContainer (`.devcontainer/devcontainer.json`) nutzt dieselbe `docker-compose.sail.yaml`. VS Code läuft dann im Container, mit allen Erweiterungen des Projekts (Intelephense, Volar, Tailwind, Prettier, Xdebug …); PHP, Composer und Node gibt es nur dort.

**Voraussetzungen:** Docker (unter Windows mit WSL2, Repository wie bei Weg A im WSL-Dateisystem), [VS Code](https://code.visualstudio.com/) mit der Erweiterung [Dev Containers](https://marketplace.visualstudio.com/items?itemName=ms-vscode-remote.remote-containers).

1. Klonen und `.env` anlegen wie bei [Weg A, Schritt 1 und 2](#weg-a-laravel-sail-empfohlen).
2. PHP-Pakete einmal installieren wie bei [Weg A, Schritt 3](#weg-a-laravel-sail-empfohlen): Die Compose-Datei baut das Image aus `vendor/laravel/sail`.
3. Ordner in VS Code öffnen, unten links auf `><` klicken und **„Reopen in Container“** wählen. Der erste Start dauert einige Minuten.
4. Im Terminal von VS Code (läuft schon im Container):

   ```bash
   php artisan key:generate
   php artisan migrate:fresh --seed
   npm install
   npm run dev
   ```

5. <http://localhost> öffnen.

Im DevContainer laufen Befehle ohne `sail`-Präfix, auch `git commit`.

### Weg C: Nativ auf dem Rechner

PHP und Node laufen direkt auf dem Rechner. Datenbank, Redis und MinIO kann man trotzdem bequem über Docker starten (C1) oder ganz ohne Docker arbeiten (C2).

**Voraussetzungen**

- **PHP 8.5** mit den Erweiterungen `bcmath`, `gd`, `mbstring`, `pdo_mysql` (oder `pdo_sqlite`), `zip`, optional `redis` und `gmp` (schnellerer Web-Push).
  - Windows: [windows.php.net](https://windows.php.net/download/) oder [Laravel Herd](https://herd.laravel.com/); die Erweiterungen in der `php.ini` einschalten.
  - macOS: `brew install php` oder Laravel Herd.
  - Linux: Pakete der Distribution, z. B. `ppa:ondrej/php` unter Ubuntu.
- [Composer 2](https://getcomposer.org/download/) und **Node 24** (z. B. über [nvm](https://github.com/nvm-sh/nvm) bzw. [nvm-windows](https://github.com/coreybutler/nvm-windows)).
- Git. **Unter Windows** checkt Git dank `.gitattributes` alle Dateien mit LF aus; bei einem älteren Klon einmal neu auschecken, siehe [Häufige Probleme](#häufige-probleme).

**1. Klonen, `.env`, Pakete**

```bash
git clone https://github.com/fsr5-fhaachen/stocks.git
cd stocks
cp .env.example .env
composer install
npm install
php artisan key:generate
```

`npm install` schaltet auch den Formatier-Hook ein.

**2a. Variante C1: Datenbank, Redis und MinIO über Docker**

Nur die Dienste aus der Sail-Datei starten, die App selbst läuft nativ:

```bash
docker compose -f docker-compose.sail.yaml up -d mariadb redis minio minio-setup
```

In der `.env` die Dienste über `127.0.0.1` ansprechen:

```env
DB_HOST=127.0.0.1
REDIS_HOST=127.0.0.1
AWS_ENDPOINT=http://127.0.0.1:9000
```

**2b. Variante C2: ganz ohne Docker**

```env
DB_CONNECTION=sqlite
# DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD auskommentieren
QUEUE_CONNECTION=database
```

```bash
# Windows (PowerShell): New-Item database/database.sqlite
touch database/database.sqlite
```

SQLite und die Queue in der Datenbank reichen für die Entwicklung. Ohne MinIO fehlen nur die Fotos (Hochladen schlägt fehl, die Demo-Daten lassen sie weg); wer Fotos braucht, nimmt C1 oder einen eigenen MinIO. Statt SQLite geht auch eine lokale MariaDB oder MySQL mit den Werten aus `.env.example`.

**3. Datenbank und Demo-Daten**

```bash
php artisan migrate:fresh --seed
```

**4. Starten**, jeweils in einem eigenen Terminal:

```bash
php artisan serve          # http://127.0.0.1:8000
npm run dev                # Oberfläche mit Neuladen bei Änderungen
php artisan queue:listen   # verschickt Mails und Push (sonst bleiben sie in der Queue)
php artisan schedule:work  # Erinnerungen, Mailserver-Prüfung, Update-Prüfung (optional)
```

**Fertig:** <http://127.0.0.1:8000> öffnen.

## Nach dem ersten Start

**Anmelden** mit einem der Demo-Konten, alle mit Passwort `correct horse battery` und Kiosk-PIN `482915`:

| Konto               | Rolle           |
| ------------------- | --------------- |
| `admin@example.com` | Admin           |
| `demo1@example.com` | Vorstand        |
| `demo2@example.com` | FSR-Mitglied    |
| `demo3@example.com` | Freies Mitglied |

**Demo-Daten** kommen aus einem Generator mit festem Startwert, nicht aus Einträgen von Hand ([DEMO.md](documentation/DEMO.md)):

```bash
php artisan stocks:demo         # Datenbank leeren und neu erzeugen (= migrate:fresh --seed)
php artisan stocks:demo-sheet   # A4-Blatt mit Testetiketten: documentation/demo/testetiketten.pdf
```

Das Blatt mit Testetiketten lässt sich auch in der App herunterladen; damit kann man Scannen, Ausgabe und Rückgabe ohne Etikettendrucker durchspielen.

**Gut zu wissen**

- **Mails** gehen mit `APP_DEBUG=true` nie raus, sondern landen in `storage/logs/laravel.log`, auch mit eingetragenem Mailserver. Zum Prüfen des Mail-Designs `MAIL_SEND_IN_DEBUG=true` setzen oder die Vorschau unter Verwaltung › Einstellungen › Benachrichtigungen nutzen ([NOTIFICATIONS.md](documentation/NOTIFICATIONS.md)).
- **MinIO-Konsole** (Sail und C1): <http://localhost:9001>, Zugang `minioadmin` / `minioadmin123`.
- **Handy im WLAN testen** (Kamera, Direktdruck brauchen HTTPS): HTTPS-Modus von Vite, siehe [SETUP.md](documentation/SETUP.md#https-im-entwicklungsbetrieb-handy-im-wlan).
- **Version im Menü** zeigt lokal `dev`; die echte Version kommt erst beim Bauen des Images ([DEPLOY.md](documentation/DEPLOY.md)).
- **Telescope** (Anfragen, Queries, Jobs ansehen) unter `/telescope`, nur lokal.

## Vor dem Commit

Der Hook in `.githooks/pre-commit` formatiert die gestagten Dateien automatisch mit Prettier und Pint. Eingeschaltet wird er mit `npm install` bzw. `npm run prepare`, auch über `sail npm install`. Details in [CONTRIBUTING.md](CONTRIBUTING.md), dort stehen auch Branches und das Format der Commit-Nachrichten (Conventional Commits).

Dieselben Prüfungen wie in der CI (bei Sail jeweils mit `sail` davor):

```bash
php vendor/bin/phpunit      # oder: php artisan test --compact
vendor/bin/pint --test
npm run lint
npm run build
```

## Häufige Probleme

| Problem                                                                                 | Lösung                                                                                                                                                                                  |
| --------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Pint meldet unter Windows fast jede Datei mit `line_ending`                             | Der Klon hat noch CRLF. Einmal alles committen, dann `git rm --cached -r -q .` und `git reset --hard`: Git checkt danach alles mit LF aus (`.gitattributes`).                           |
| PHPUnit bricht mit „Premature end of PHP process“ ab                                    | Zu wenig Speicher; `phpunit.xml.dist` setzt 1 GB. Mit einer älteren Kopie: `php -d memory_limit=1G vendor/bin/phpunit`.                                                                 |
| `stocks:demo` bricht mit „Allowed memory size … exhausted“ ab (Telescope im Stacktrace) | Telescope zeichnete jede Abfrage des Generators auf; `config/telescope.php` schließt die Demo-Befehle jetzt aus. Mit einer älteren Kopie: `php -d memory_limit=1G artisan stocks:demo`. |
| „Unable to locate file in Vite manifest“                                                | `npm run dev` läuft nicht. Starten oder einmal `npm run build`.                                                                                                                         |
| Seite lädt ohne Styles, Konsole zeigt Fehler zu Port 5173                               | `APP_URL` passt nicht zur Adresse im Browser; Vite nimmt den Host aus `APP_URL`. Danach `npm run dev` neu starten.                                                                      |
| `./vendor/bin/sail` meldet `UtilTranslatePathList` oder „No such file or directory“     | WSL kann den Pfad nicht übersetzen (Klon auf einem Windows- oder Netzlaufwerk). Den Klon ins WSL-Dateisystem legen (Weg A) oder [Weg C1](#weg-c-nativ-auf-dem-rechner) nehmen.          |
| Build bricht mit `groupadd: invalid group ID 'sail'` ab                                 | `docker compose` ohne Sail mit einem älteren Stand: `WWWUSER=1000` und `WWWGROUP=1000` in die `.env` (heute Vorgabe in `docker-compose.sail.yaml`).                                     |
| Sail: „port is already allocated“                                                       | Anderen Port in der `.env` setzen (`APP_PORT`, `FORWARD_DB_PORT`, `FORWARD_REDIS_PORT`, `FORWARD_MINIO_PORT`, `VITE_PORT`).                                                             |
| Sail: Verbindung zur Datenbank schlägt fehl                                             | `DB_HOST=mariadb` und `REDIS_HOST=redis` in der `.env`, dann `sail artisan config:clear`.                                                                                               |
| Mails oder Push kommen nicht an                                                         | Läuft die Queue (`queue:listen` bzw. Dienst `queue`)? Steht `APP_DEBUG=true`, gehen Mails nur ins Log.                                                                                  |
| Fotos fehlen                                                                            | MinIO läuft nicht oder `AWS_ENDPOINT` stimmt nicht (Sail: `http://minio:9000`, nativ: `http://127.0.0.1:9000`).                                                                         |
| Commit bricht mit „node/npx not found“ ab                                               | Der Hook findet Node nicht (oft in GUI-Clients). Node installieren bzw. nvm einrichten; einmalig überspringen mit `git commit --no-verify`.                                             |

## Dokumentation

| Datei                                                            | Inhalt                                                           |
| ---------------------------------------------------------------- | ---------------------------------------------------------------- |
| [documentation/CORE.md](documentation/CORE.md)                   | Aufbau, Anmeldung, Rollen und Rechte, Oberflächen, Datenbank     |
| [documentation/INVENTORY.md](documentation/INVENTORY.md)         | Standorte, Gegenstände, Boxen, Nummern, Umlagern, Fotos          |
| [documentation/LABELS.md](documentation/LABELS.md)               | Etiketten, Vorlagen, Drucker, Kurzlinks, Scannen, Web-App        |
| [documentation/LOANS.md](documentation/LOANS.md)                 | Verleih: Buchungen, Ausgabe, Rückgabe, Verleihseite              |
| [documentation/NOTIFICATIONS.md](documentation/NOTIFICATIONS.md) | Mails, Push, Warteschlange und Zeitplan                          |
| [documentation/KIOSK.md](documentation/KIOSK.md)                 | Modul Kiosk: Tablets koppeln, Sitzung, Vorgänge am Tablet        |
| [documentation/INTERFACES.md](documentation/INTERFACES.md)       | Welche Vorgänge auf welcher Oberfläche (PC, Handy, Kiosk) fehlen |
| [documentation/MODULES.md](documentation/MODULES.md)             | Module bauen, Erweiterungspunkte                                 |
| [documentation/DEMO.md](documentation/DEMO.md)                   | Demo-Daten und Testetiketten                                     |
| [documentation/TABLE_POLLING.md](documentation/TABLE_POLLING.md) | Tabellen mit seitenweisem Laden und Live-Update                  |
| [documentation/SETUP.md](documentation/SETUP.md)                 | Instanz aufsetzen und aktualisieren, Umgebungsvariablen          |
| [documentation/DEPLOY.md](documentation/DEPLOY.md)               | Dev-Instanz über GitHub Actions                                  |
| [documentation/DECISIONS.md](documentation/DECISIONS.md)         | Entscheidungsprotokoll                                           |
| [CONTRIBUTING.md](CONTRIBUTING.md)                               | Branches, Commits, Werkzeuge, Formatier-Hook                     |

## Lizenz

MIT, siehe [LICENSE](LICENSE). Portals © FH-Aachen FSR5.
