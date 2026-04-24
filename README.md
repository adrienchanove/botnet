# botnet

Réseau de crawler composé d'un **Master API** (MariaDB) et d'un **Worker CLI** (PHP) configurés via fichiers INI.

## Structure

- `Master/index.php` : API de gestion workers/jobs/résultats
- `Master/config.sample.ini` : configuration MariaDB
- `Worker/worker.php` : worker CLI (boucle de polling + crawl)
- `Worker/config.sample.ini` : configuration worker/API

## Endpoints Master

- `POST /workers/register` `{ "name": "worker-1" }`
- `POST /jobs/enqueue` `{ "url": "https://example.com", "delay_seconds": 2 }`
- `GET /jobs/next?worker_id=1`
- `POST /jobs/result` `{ "worker_id":1, "job_id":1, "status":"done|failed", "html":"...", "error":null }`
- `GET /health`

## Lancement rapide

1. Copier `Master/config.sample.ini` en `Master/config.ini` puis renseigner la base MariaDB.
2. Démarrer l'API master:
   ```bash
   php -S 127.0.0.1:8000 -t Master Master/index.php
   ```
3. Copier `Worker/config.sample.ini` en `Worker/config.ini` puis adapter `base_url`.
4. Démarrer le worker:
   ```bash
   php Worker/worker.php
   ```
