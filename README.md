# crø

Chat-Anwendung – PHP-Backend, React-Frontend, MariaDB.

<img width="1612" height="989" alt="cro_chat2" src="https://github.com/user-attachments/assets/92e9c2a0-d847-4474-a81a-daa6c12fd935" />
<img width="1612" height="989" alt="cro_chat1" src="https://github.com/user-attachments/assets/51fbd305-3e88-499d-9608-807419dfa583" />

## Quick Start

### Voraussetzungen

- [XAMPP](https://www.apachefriends.org/) (PHP 8.2+, MariaDB, Apache)
- [Node.js](https://nodejs.org/) ≥ 18

### 1. Datenbank einrichten

```sql
-- In phpMyAdmin oder mysql CLI:
SOURCE C:/xampp/htdocs/crø/database/schema.sql;
SOURCE C:/xampp/htdocs/crø/database/seed.sql;
```

### 2. Backend

```powershell
cd apps/chat-api
copy .env.example .env          # Datenbankzugangsdaten anpassen
composer install
.\scripts\setup-xampp.ps1       # Kopiert nach C:\xampp\htdocs\chat-api
```

API läuft unter `http://localhost/chat-api/`.

### 3. Frontend

```powershell
cd apps/chat-web
npm install
npm run dev
```

Öffne `http://localhost:5173`.

### 4. Dev-Account

| Feld     | Wert                   |
| -------- | ---------------------- |
| E-Mail   | `tom.martinez@cro.dev` |
| Passwort | `password`             |


---

## Struktur

```
apps/
  chat-api/          PHP Backend (Custom-Framework, XAMPP)
  chat-web/          React 18 + TypeScript + Vite (PWA)
  desktop/           Tauri 2 Desktop-Wrapper
  realtime/          Node.js WebSocket-Gateway (horizontal skalierbar)
packages/
  shared-types/      Gemeinsame TypeScript-Typen
database/
  schema.sql         27 Tabellen (MariaDB, utf8mb4)
  seed.sql           27 User, 1 Space, 4 Channels, Demo-Nachrichten
docs/
  architecture.md    Architektur-Übersicht
scripts/
  setup-xampp.ps1    Deployment-Skript → C:\xampp\htdocs\chat-api
  backup.ps1         Datenbank-Backup (GZip, Rotation)
  restore.ps1        Datenbank-Restore (Dry-Run, GZip-Support)
  deploy.ps1         Zero-Downtime-Deployment (Symlink-basiert)
  migrate.ps1        Migrations-Runner mit Tracking + Checksums
```

## Backend

**Stack:** PHP 8.2, Custom-Framework (Router, Request/Response, Middleware-Pipeline), PSR-4 Autoloading, kein externes Framework.

| Schicht    | Anzahl | Beispiele                                                                                                                                                                                                                                                                                  |
| ---------- | ------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Controller | 27     | Auth, Channel, Message, Conversation, Presence, Search, Attachment, Key, ReadReceipt, Space, User, Reaction, Pin, SavedMessage, Thread, Mention, Notification, Moderation, Job, Compliance, Device, RichContent, Analytics, Scaling, Security, Ai, **Call**                                |
| Service    | 28     | Auth, Channel, Message, Notification, Mention, Reaction, Thread, Attachment, Presence, Role, Moderation, Job, Compliance, Push, RichContent, Search, Analytics, Scaling, MFA, SSO, DeviceTracker, AbuseDetection, SessionManager, SecretManager, SecurityLogger, Ai, **Call**, **DevCall** |
| Repository | 19     | Message, Search, Key, Channel, Conversation, User, Space, Event, Notification, Thread, Moderation, Job, Compliance, Device, RichContent, Analytics, Ai, **Call**, …                                                                                                                        |
| Middleware | 4      | Auth, CSRF, CORS, RateLimit                                                                                                                                                                                                                                                                |

### Sicherheit

- **CSRF** – Synchronizer-Token-Pattern (`X-CSRF-Token` Header)
- **Rate-Limiting** – DB-basiertes Sliding-Window (Login 10/5 min, Nachrichten 30/min) + Redis-Sliding-Window
- **Security-Header** – `nosniff`, `DENY`, `no-store`, `strict-origin-when-cross-origin`
- **Session** – `httponly`, `secure` (Prod), `samesite=Lax`, 2 h Lifetime + DB-Session-Management
- **Passwörter** – bcrypt, leerer Hash wird abgelehnt
- **Uploads** – Whitelist (kein SVG), Größen-Limit 10 MB

### Enterprise Security

#### SSO (OIDC + SAML 2.0)

- **OIDC** – Authorization Code Flow (Auth URL → Callback → Token Exchange → UserInfo)
- **SAML 2.0** – AuthnRequest → IdP Redirect → SAMLResponse Parsing + Attribut-Extraktion
- **Provider-Management** – CRUD pro Space mit verschlüsseltem Client Secret
- **Auto-Provisioning** – Automatische User-Erstellung und Space-Zuordnung beim ersten SSO-Login
- **Enforce SSO** – Passwort-Login für einen Space deaktivierbar
- **User-Linking** – Externe Identitäten mit internen Accounts verknüpfen/trennen

#### Multi-Factor Authentication (TOTP)

- **RFC 6238** – HMAC-SHA1, 6-stellige Codes, 30s Fenster, ±1 Toleranz
- **Recovery Codes** – 8 Einmalcodes (regenerierbar), verschlüsselt gespeichert
- **Setup-Flow** – Setup → QR-Code/Provisioning-URI → Code verifizieren → MFA aktiv
- **Verschlüsselung** – TOTP-Secrets via AES-256-GCM (SecretManager)

#### Device Tracking

- **Fingerprinting** – SHA-256 Hash aus User-Agent + OS + Browser
- **Trust-Management** – Geräte vertrauen, widerrufen, entfernen
- **Neue-Geräte-Warnung** – Security-Log-Eintrag bei unbekanntem Gerät
- **Session-Verknüpfung** – Geräte mit aktiven Sessions verbunden

#### Session Management

- **DB-backed Sessions** – Token-basiert (64-Byte hex), mit Device-Linking
- **Concurrent Limit** – Max. 10 gleichzeitige Sessions pro User
- **Force Logout** – Einzelne oder alle Sessions widerrufen
- **MFA-Verification State** – Session-Level MFA-Status

#### Abuse Detection

- **Score-basiert** – Vordefinierte Violations (failed_login=10, brute_force=30, csrf=25, …)
- **Auto-Blocking** – Automatische Sperre bei Schwellenwert-Überschreitung
- **Score Decay** – Zeitbasierter Abbau (10 Punkte/Stunde)
- **Manuelles Block/Unblock** – IP- und User-basiert

#### Secret Management

- **AES-256-GCM** – Verschlüsselte Secrets mit 12-Byte IV + 16-Byte Auth-Tag
- **DB Vault** – Versionierte Secrets mit Rotation, Cache → DB → Env-Fallback
- **Master Key** – Aus `SECRET_MASTER_KEY` oder `APP_KEY` abgeleitet

#### Security Logging

- **Audit Trail** – Alle sicherheitsrelevanten Events (Login, MFA, SSO, Geräte, Sessions)
- **Severity Levels** – info, warning, critical
- **Abfragbar** – Filter nach User, Event-Typ, Severity, IP, Zeitraum
- **Auto-Purge** – Konfigurierbare Aufbewahrungsdauer (Standard: 90 Tage)

| Datenmodell      | Felder                                                                        |
| ---------------- | ----------------------------------------------------------------------------- |
| `sso_providers`  | provider_type (oidc/saml), client_id, issuer_url, auto_provision, enforce_sso |
| `sso_user_links` | external_id, external_email, access_token_enc, refresh_token_enc              |
| `user_mfa`       | method (totp), secret_enc, recovery_codes_enc, is_enabled, verified_at        |
| `user_devices`   | device_hash, browser, os, device_type, is_trusted, last_active_at             |
| `user_sessions`  | session_token, device_id, mfa_verified, expires_at, last_activity_at          |
| `security_log`   | event_type, severity (info/warning/critical), ip_address, details JSON        |
| `abuse_scores`   | subject_type (ip/user), score, violations JSON, blocked_until                 |
| `secrets_vault`  | key_name, encrypted_value, version, rotated_at                                |

### Performance

- **Query-Profiling** – `ProfiledPDO` + Debug-Header (`X-Query-Count`, `X-Query-Time-Ms`)
- **N+1 eliminiert** – Batch-Loading für Attachments, Conversation-Members, Search-Enrichment
- **FULLTEXT-Suche** – `MATCH AGAINST` in Boolean-Mode statt `LIKE '%…%'`
- **Presence** – Expiry im Heartbeat, leichtgewichtige Status-Map (`id → status`)
- **Indizes** – Compound-Indizes auf allen Foreign-Key-Pfaden, `idx_status_lastseen`

### Job System

Asynchrone Aufgabenverarbeitung mit Worker-Architektur.

- **Locking** – `SELECT … FOR UPDATE SKIP LOCKED` (MariaDB 10.6+), keine Doppelverarbeitung
- **Retry** – Exponential Backoff (30s × 4^(attempts-1)), max 3 Versuche
- **Idempotenz** – Unique-Key verhindert doppelte Dispatch; Handler prüfen Vorzustand
- **Queues** – `default`, `notifications`, `maintenance` (getrennte Worker möglich)
- **Stale-Lock-Recovery** – Jobs mit Lock > 10 min werden automatisch freigegeben

| Job-Typ                       | Handler                          | Beschreibung                                   |
| ----------------------------- | -------------------------------- | ---------------------------------------------- |
| `notification.dispatch`       | NotificationDispatchHandler      | Notification erstellen + Realtime-Event        |
| `presence.cleanup`            | PresenceCleanupHandler           | Stale Presence + Event/Job-Purge               |
| `search.reindex`              | SearchReindexHandler             | FULLTEXT-Re-Index / OPTIMIZE TABLE             |
| `attachment.process`          | AttachmentProcessHandler         | Thumbnail-Generierung, Bild-Metadaten          |
| `retention.cleanup`           | RetentionCleanupHandler          | Daten-Retention nach Aufbewahrungsregeln       |
| `compliance.action`           | ComplianceActionHandler          | Datenexport + Account-Löschung                 |
| `push.send`                   | PushSendHandler                  | Web Push an registrierte Geräte                |
| `knowledge.summarize_thread`  | KnowledgeSummarizeThreadHandler  | Thread-Zusammenfassung generieren              |
| `knowledge.summarize_channel` | KnowledgeSummarizeChannelHandler | Channel-Tages/Wochenübersicht                  |
| `knowledge.extract`           | KnowledgeExtractHandler          | Wissensextraktion aus Nachrichten              |
| `task.reminders`              | TaskReminderHandler              | Task-Erinnerungen + Overdue-Benachrichtigungen |
| `linkpreview.unfurl`          | LinkUnfurlHandler                | Link-Vorschau-Metadaten abrufen                |
| `ai.summarize_thread`         | AiSummarizeThreadHandler         | KI-Thread-Zusammenfassung generieren           |
| `ai.summarize_channel`        | AiSummarizeChannelHandler        | KI-Channel-Zusammenfassung generieren          |
| `ai.extract`                  | AiExtractHandler                 | Action-Items aus Nachrichten extrahieren       |
| `ai.embed`                    | AiEmbedHandler                   | Semantische Embeddings generieren              |

```powershell
# Worker starten
cd apps/chat-api
php worker.php                      # Default-Queue
php worker.php --queue=maintenance  # Maintenance-Queue
php worker.php --once               # Ein Job, dann Exit
```

### Knowledge Layer

Strukturierte Wissensextraktion aus Chat-Daten mit Rückverlinkung auf Originalnachrichten.

- **Topics** – Extrahierte Themen aus Konversationen, pro Space/Channel
- **Decisions** – Entscheidungen mit Status (proposed/accepted/rejected/superseded)
- **Summaries** – Thread-, Channel-, Tages- und Wochenübersichten
- **Entries** – Einzelne Wissenselemente (Facts, HowTos, Links, Definitionen, Action Items)
- **Source Links** – Jeder Wissenseintrag verlinkt zurück zu den Quellnachrichten (many-to-many)
- **FULLTEXT-Suche** – Wissenseinträge per `MATCH AGAINST` durchsuchbar
- **Async Generierung** – Thread/Channel-Summaries und Extraktion über Job-System
- **Heuristische Extraktion** – Pattern-basierte Erkennung von Entscheidungen, TODOs, Links, Code

| Datenmodell           | Felder                                                                    |
| --------------------- | ------------------------------------------------------------------------- |
| `knowledge_topics`    | name, slug, description, channel, message_count, last_activity            |
| `knowledge_decisions` | title, description, status, decided_by, source_message_id                 |
| `knowledge_summaries` | scope (thread/channel/daily/weekly), key_points, participants, period     |
| `knowledge_entries`   | type (fact/howto/link/reference/definition/action_item), tags, confidence |
| `knowledge_sources`   | entry↔message, summary↔message, decision↔message (many-to-many)           |
| `knowledge_jobs`      | Cursor-Tracking: letzte verarbeitete Message, nächster Run                |

### Task Management

Aufgabenverwaltung mit direkter Verknüpfung zu Chat-Nachrichten und Threads.

- **Tasks** – Aufgaben mit Status (open/in_progress/done/cancelled), Priorität (low/normal/high/urgent), Due Date
- **Zuweisung** – Mehrfachzuweisung an Space-Mitglieder, automatische Selbstzuweisung beim Erstellen
- **Kommentare** – Kommentare an Tasks, Benachrichtigung an alle Assignees
- **Reminders** – Erinnerungen mit frei wählbarem Zeitpunkt, automatische Verarbeitung über Job-System
- **Message-to-Task** – Nachrichten direkt in Tasks umwandeln (Titel aus Nachrichtentext generiert)
- **Activity Log** – Lückenlose Änderungshistorie (Status, Zuweisung, Kommentare)
- **Notifications** – Integration mit Notification-System (task_assigned, task_comment, task_reminder, task_overdue)
- **Overdue-Erkennung** – Automatische Benachrichtigung bei überfälligen Tasks (idempotent, 1× pro Tag)

| Datenmodell      | Felder                                                            |
| ---------------- | ----------------------------------------------------------------- |
| `tasks`          | title, description, status, priority, due_date, source_message_id |
| `task_assignees` | task_id, user_id, assigned_by                                     |
| `task_comments`  | task_id, user_id, body                                            |
| `task_reminders` | task_id, user_id, remind_at, sent_at                              |
| `task_activity`  | task_id, user_id, action, old_value, new_value                    |

### Rich Content

Rich-Text-Verarbeitung, Codeblöcke, Link-Unfurling, Snippets und gemeinsame Entwürfe.

- **Markdown-Rendering** – Bold, Italic, Strikethrough, Code (Inline + Fenced), Links, Blockquotes
- **HTML-Sanitization** – Entfernt Script/Style/Iframe-Tags, Event-Handler, `javascript:`/`data:`-URIs
- **Snippets** – Code-Snippets mit Sprach-Erkennung (23 Sprachen), FULLTEXT-Suche, öffentlich/privat
- **Link Unfurling** – Async Metadaten-Abruf (OpenGraph + Meta-Fallback), SSRF-Schutz (blockiert private IPs, localhost, Cloud-Metadata)
- **Shared Drafts** – Entwürfe mit Versionierung, Kollaboratoren (view/edit), Publish-to-Message
- **Draft Publishing** – Entwurf direkt als Nachricht in Channel/Conversation veröffentlichen

| Datenmodell           | Felder                                                                                      |
| --------------------- | ------------------------------------------------------------------------------------------- |
| `snippets`            | title, language, content, description, is_public, space_id, FULLTEXT-Index                  |
| `link_previews`       | message_id, url, title, description, image_url, site_name, status (pending/resolved/failed) |
| `drafts`              | title, body, format (markdown/plaintext), is_shared, version, published_message_id          |
| `draft_collaborators` | draft_id, user_id, permission (view/edit)                                                   |

### Advanced Search

Facettierte Suche mit Ranking, Highlighting, gespeicherten Suchen und Suchverlauf.

- **FULLTEXT-Ranking** – Relevanz-Scoring via `MATCH AGAINST` in Boolean-Mode, sortierbar nach Relevanz/Datum
- **Facetten** – Channel-Facetten (Top 10), Autoren-Facetten (Top 10), Zeitraum-Facetten (Heute/Woche/Monat)
- **Filter** – Channel, Autor, Zeitraum (after/before), has_attachment, has_reaction, in_thread
- **Highlighting** – Offset/Length/Term-Arrays für Frontend-`<mark>`-Rendering
- **Gespeicherte Suchen** – CRUD mit Limit (50/User), Space-Mitgliedschaftsprüfung, Execute mit Overrides
- **Suchverlauf** – Automatische Aufzeichnung, Auto-Cleanup (50 Einträge/User), Löschbar
- **Autocomplete** – Vorschläge aus Suchverlauf (GROUP BY query, nach Aktualität sortiert)
- **Berechtigungen** – Alle Ergebnisse respektieren Channel-/DM-Berechtigungen
- **Pagination** – Seiten-basiert mit per_page (max 50), has_more-Flag

| Datenmodell      | Felder                                                              |
| ---------------- | ------------------------------------------------------------------- |
| `saved_searches` | user_id, space_id, name, query, filters (JSON), notify, last_run_at |
| `search_history` | user_id, query, filters (JSON), result_count                        |

### Analytics

Produkt- und System-Metriken mit Datenschutz-konformem Event-Tracking.

- **Event-Tracking** – 34 Produkt-Events (message.sent, reaction.added, search.executed, …) + 7 System-Events (job.completed, api.error, …)
- **Privacy** – User-IDs werden per SHA-256 mit tagesrotierendem Salt gehasht, kein Rückschluss auf Einzelpersonen
- **DAU/WAU/MAU** – Tägliche, wöchentliche und monatliche aktive Nutzer + Stickiness (DAU/MAU)
- **Channel-Aktivität** – Top-Channels nach Event-Count und Unique-Users
- **Antwortzeiten** – Durchschnittliche/minimale Reply-Response-Times aus Nachrichtenverknüpfungen
- **Suchnutzung** – Tägliche Suchzähler, Unique-Searchers, Top-Suchbegriffe via JSON_EXTRACT
- **Notification-Engagement** – Sent vs. Clicked pro Tag
- **Dashboard** – Kombinierter Endpoint mit allen Metriken (Admin-only)
- **Tagesaggregation** – Compute + Upsert täglicher Metriken (via Job oder manuell)
- **Batch-Tracking** – Mehrere Events in einem Request, ungültige Events werden übersprungen
- **Daten-Retention** – Purge mit Mindest-Aufbewahrung (30 Tage)
- **Event-Typen** – Whitelist-basiert, unbekannte Events werden abgelehnt

| Datenmodell               | Felder                                                                               |
| ------------------------- | ------------------------------------------------------------------------------------ |
| `analytics_events`        | space_id, user_hash (SHA-256), event_type, event_category, channel_id, metadata JSON |
| `analytics_daily`         | space_id, metric_date, metric_name, metric_value DECIMAL, breakdown JSON             |
| `analytics_system_events` | space_id, event_type, severity (info/warning/error), metadata JSON                   |

### KI-Features

KI-gestützte Funktionen mit austauschbarem Provider, asynchroner Verarbeitung und Rückverlinkung auf Originalnachrichten.

- **Thread-Zusammenfassungen** – Automatische Zusammenfassung von Threads mit Kernpunkten, Teilnehmern und Quellnachweisen
- **Channel-Zusammenfassungen** – Tages-/Zeitraum-Übersichten für Channels mit Top-Themen und Aktivitätsstatistiken
- **Action-Item-Extraktion** – Erkennung von TODOs, Aufgaben und Entscheidungen aus Nachrichten (mit Konfidenz-Score)
- **Semantische Suche** – Vektorbasierte Ähnlichkeitssuche (Cosine Similarity) mit FULLTEXT-Fallback
- **Antwortvorschläge** – Kontextbasierte Reply-Suggestions mit Akzeptanz-Tracking
- **Provider-Abstraktion** – `AiProvider`-Interface (summarize, extractActions, embed, suggest), austauschbar
- **Heuristic-Fallback** – Pattern-basierter Provider ohne API-Key (Regex + CRC32-Pseudo-Embeddings)
- **Async Verarbeitung** – Alle KI-Operationen über Job-System dispatchbar (cursor-basiert)
- **Getrennte Datenhaltung** – Eigene `ai_*`-Tabellen, keine Vermischung mit Primärdaten
- **Nachvollziehbarkeit** – `ai_summary_sources` verlinkt jede Zusammenfassung auf Quellnachrichten
- **Config pro Space** – Provider, Modell und API-Key pro Space konfigurierbar (AES-256-GCM verschlüsselt)

| Datenmodell          | Felder                                                                       |
| -------------------- | ---------------------------------------------------------------------------- |
| `ai_summaries`       | scope (thread/channel), summary, key_points JSON, model, tokens_used, period |
| `ai_summary_sources` | summary_id → message_id (Quellnachweise, many-to-many)                       |
| `ai_action_items`    | content, assignee, due_date, status, confidence, source_message_id           |
| `ai_embeddings`      | message_id (UNIQUE), embedding BLOB, model, dimensions                       |
| `ai_suggestions`     | scope (thread/channel/conversation), suggestions JSON, accepted_index, model |
| `ai_jobs`            | job_type, scope_type, scope_id, last_cursor, next_run_at (Cursor-Tracking)   |
| `ai_provider_config` | space_id (UNIQUE), provider, model, api_key_enc, is_enabled, settings JSON   |

### 1:1 Audio-Calls (WebRTC)

Echtzeit-Sprachanrufe zwischen zwei Nutzern, vollständig integriert in das bestehende Realtime- und Messaging-System.

- **Signaling** – Offer/Answer/ICE-Candidate-Austausch über bestehenden WebSocket-Gateway (`user:{id}`-Rooms)
- **Call-Lifecycle** – `initiating → ringing-outgoing/ringing-incoming → connecting → active → ending → idle`
- **ICE/STUN** – Browser-native WebRTC mit konfigurierbarem STUN-Server
- **Audio-Geräte** – Mikrofon-Auswahl, Lautstärke-Anzeige, Mute/Unmute, Geräte wechseln während des Anrufs
- **Anruf-History** – Alle Anrufe mit Dauer und Ergebnis persistent gespeichert, im Chat-Header abrufbar
- **Push-Events** – `call.ringing`, `call.accepted`, `call.ended`, `call.rejected`, `call.failed`, `webrtc.offer/answer/ice`
- **Glare-Handling** – Gleichzeitige Anruf-Initiierung per Tie-Breaking (niedrigere `user_id` gewinnt)
- **Concurrency** – Race-Condition-sichere DB-Locks, doppelte Ringing-Events werden ignoriert
- **Call Overlay** – Globale UI-Schicht über der gesamten App (klingeln, verbinden, aktiver Anruf, Fehler)
- **Dev-Simulator** – Floating Panel (`VITE_CALL_SIMULATION=true`) simuliert eingehende Anrufe ohne echtes Mikrofon

| Datenmodell     | Felder                                                                                   |
| --------------- | ---------------------------------------------------------------------------------------- |
| `calls`         | status, caller/callee user_id, conversation_id, started_at, duration_seconds, end_reason |
| `call_history`  | View/Tabelle mit formatierten Anruf-Einträgen pro Conversation                           |
| `call_presence` | Echtzeit-Verfügbarkeit (available/in_call/unavailable) pro User                          |

```powershell
# Simulation aktivieren (kein Mikrofon nötig)
# In apps/chat-web/.env.local:
VITE_CALL_SIMULATION=true
```

### Scaling & Infrastructure

Produktions-fähige Skalierungsinfrastruktur mit Redis, S3, Zero-Downtime-Deployment.

#### Caching (Redis + In-Memory Fallback)

- **Cache-Aside** – `Cache::remember()` mit TTL-basierter Expiration
- **Bulk-Ops** – `getMany()` / `setMany()` (Redis MGET)
- **Tag-Invalidierung** – Pattern-basiertes Cache-Busting (`Cache::invalidateTag('user:1')`)
- **Distributed Locks** – `Cache::lock()` / `unlock()` via Redis SETNX
- **Atomic Counter** – `Cache::increment()` für Rate-Limiting und Zähler
- **Redis Sessions** – Horizontale Session-Sharing über Redis (opt-in via `REDIS_SESSIONS=true`)
- **Graceful Fallback** – Automatischer In-Memory-Fallback wenn Redis unavailable

#### Queue System (Redis + DB Fallback)

- **Redis-Queue** – BRPOP-basiert (blocking pop), niedrige Latenz, wenig CPU
- **Delayed Jobs** – Zeitgesteuerte Ausführung via ZADD (Sorted Set)
- **Reserved Set** – Laufende Jobs mit TTL-basierter Stale-Lock-Recovery
- **Failed Jobs** – Separate Queue mit Retry/Flush-Operationen
- **Idempotenz** – Dedup via SETNX (48h TTL)
- **DB-Fallback** – Automatischer Rückfall auf `SELECT FOR UPDATE SKIP LOCKED`

```powershell
# Redis-Worker (mit automatischem DB-Fallback)
php redis-worker.php                     # Default-Queue
php redis-worker.php --queue=notifications --timeout=10
php redis-worker.php --once              # Ein Job, dann Exit
```

#### Object Storage (Local + S3)

- **Treiber** – `local` (Dateisystem) und `s3` (AWS S3, MinIO, DigitalOcean Spaces)
- **S3 ohne SDK** – Pure cURL + AWS Signature V4 (kein aws-sdk-php nötig)
- **Pre-Signed URLs** – Temporäre Download-URLs mit Ablaufzeit
- **CDN-Support** – Konfigurierbare CDN-URL für öffentliche Dateien
- **Path-Traversal-Schutz** – `realpath()`-Validierung bei Local-Treiber

#### Horizontale Skalierung

- **Realtime Pub/Sub** – Redis-basierte Cross-Instance-Broadcasts für WebSocket-Gateway
- **Instance-Filter** – Nachrichten werden auf dem Origin-Server nicht doppelt gesendet
- **Graceful Shutdown** – SIGTERM/SIGINT Handler für sauberes Herunterfahren
- **Health Check** – Readiness-Endpoint prüft DB, Cache, Queue und Storage

#### Backup & Restore

```powershell
# Backup
.\scripts\backup.ps1                           # Vollbackup (GZip)
.\scripts\backup.ps1 -Mode schema              # Nur Schema
.\scripts\backup.ps1 -Keep 10                  # Rotation: 10 behalten

# Restore
.\scripts\restore.ps1 -File backup.sql.gz      # Restore aus GZip
.\scripts\restore.ps1 -File backup.sql -DryRun # Dry-Run
```

#### Deployment & Migrationen

```powershell
# Zero-Downtime-Deployment (Symlink-basiert)
.\scripts\deploy.ps1 -Source . -Target C:\app\chat-api
.\scripts\deploy.ps1 -Rollback -Target C:\app\chat-api

# Migrationen mit Tracking
.\scripts\migrate.ps1                          # Pending Migrationen ausführen
.\scripts\migrate.ps1 -DryRun                  # Nur anzeigen
.\scripts\migrate.ps1 -Force                   # Ohne Bestätigung
```

## Frontend

**Stack:** React 18.3, TypeScript 5.5, Vite 5.3, PWA (Service Worker, Web Push).

| Bereich    | Inhalt                                                                                               |
| ---------- | ---------------------------------------------------------------------------------------------------- |
| Pages      | LoginPage, ChatPage                                                                                  |
| Features   | auth, channels, conversations, members, messages, presence, **calls**                                |
| Components | ChannelList, MessageList, MessageComposer, MemberList, ChatHeader, Sidebars, Search, **CallOverlay** |
| API-Client | `src/api/client.ts` – Fetch-Wrapper mit CSRF-Token                                                   |
| PWA        | Service Worker, Push Notifications, Offline-Caching, Background Sync                                 |
| Deep Links | Hash-basiertes Routing für Channels, Conversations, Messages                                         |

### Nachrichten-Aktionen

- **Toolbar** – Emoji 😀 und Bearbeiten ✏️ als sichtbare Icons, alle weiteren Aktionen (Antwort, Thread, Pin, Speichern, Löschen) im „···“-Dropdown-Menü
- **Emoji-Picker** – Position: fixed, Viewport-aware (clamps an Ränder), 270px breit, 7 Spalten, leichter Schatten
- **Inline-Edit** – Eigener Emoji-Picker im Editor mit Cursor-Position-Insertion
- **Antwort-Fokus** – Input wird automatisch fokussiert wenn Antwort-Funktion ausgewählt wird
- **Lösch-Berechtigung** – Nur Autor, Admin, Owner und Moderator können Nachrichten löschen (Frontend + Backend)
- **Edit** – Nur Autor kann eigene Text-Nachrichten bearbeiten

### Saved Messages

- **Self-Conversation** – Jeder Benutzer hat automatisch einen „Saved Messages“-Direktchat
- **Auto-Erstellung** – Backend erstellt die Self-Conversation beim Laden der Konversationsliste (`listForUser`)
- **UI** – Bookmark-Icon, sortiert an erster Stelle der Direct Chats, kein Presence-Dot
- **Header** – Zeigt „Saved Messages“ als Titel, kein Call-Button

### Berechtigungen

- **Channel-Erstellung** – Nur Admins und Owner können neue Channels erstellen (Backend + Frontend)
- **Nachrichten löschen** – Nur Autor + Admin/Owner/Moderator
- **Nachrichten bearbeiten** – Nur Autor, nur Text-Nachrichten

## Datenbank

60+ Tabellen: `users`, `spaces`, `space_members`, `channels`, `channel_members`, `conversations`, `conversation_members`, `messages`, `message_edits`, `attachments`, `user_keys`, `conversation_keys`, `read_receipts`, `rate_limits`, `reactions`, `threads`, `mentions`, `notifications`, `domain_events`, `pinned_messages`, `saved_messages`, `moderation_actions`, `jobs`, `push_subscriptions`, `push_delivery_log`, `sync_cursors`, `vapid_keys`, `tasks`, `task_assignees`, `task_comments`, `task_reminders`, `task_activity`, `snippets`, `link_previews`, `drafts`, `draft_collaborators`, `analytics_events`, `analytics_daily`, `analytics_system_events`, `sso_providers`, `sso_user_links`, `user_mfa`, `user_devices`, `user_sessions`, `security_log`, `abuse_scores`, `secrets_vault`, `calls`, `call_history`, `call_presence`, …

E2E-Encryption-Support über `user_keys` + `conversation_keys`.

## Tests

```powershell
cd apps/chat-api
php vendor/bin/phpunit --testdox
```

**637 Tests, 1368 Assertions** (PHPUnit 11) – Integration-Tests gegen `cro_chat_test`.

| Test-Datei              | Fokus                                                         |
| ----------------------- | ------------------------------------------------------------- |
| AuthTest                | Login, Logout, Session                                        |
| ChannelAccessTest       | Zugriffskontrolle, Rollen                                     |
| MessageTest             | CRUD, Replies, Idempotenz, Rechte                             |
| ConversationTest        | DMs, Gruppen-DMs, Sichtbarkeit                                |
| ReadReceiptTest         | Unread-Counts, Mark-as-Read                                   |
| SecurityTest            | CSRF, Rate-Limiting, Passwort-Hash                            |
| SearchTest              | FULLTEXT-Suche, Scope-Filter                                  |
| AdvancedSearchTest      | Facettierte Suche, Ranking, Highlights, Saved Searches        |
| ReactionTest            | Emoji-Reaktionen, Toggle, Aggregation                         |
| ThreadTest              | Threads, Replies, ReadState                                   |
| MentionTest             | @Mentions, Autocompletion                                     |
| NotificationTest        | Mention/DM/Thread/Reaction-Benachrichtigungen                 |
| PinAndSavedTest         | Pins, Saved Messages                                          |
| RoleServiceTest         | Rollen-Hierarchie, Berechtigungen                             |
| ModerationServiceTest   | Mod-Aktionen, Mute, Kick, Rollen-Änderung                     |
| KnowledgeTest           | Topics, Decisions, Entries, Search, Summaries                 |
| KnowledgeJobTest        | Thread/Channel-Summarize, Extract-Handler                     |
| TaskTest                | Tasks, Assignments, Kommentare, Reminders, Message-to-Task    |
| RichContentTest         | Markdown, Snippets, Link Previews, Drafts, Kollaboration      |
| AnalyticsTest           | Event-Tracking, Privacy, DAU/WAU, Dashboard, Aggregation      |
| ScalingTest             | Cache, ObjectStorage, RedisQueue, ScalingService, Admin       |
| SecurityEnterpriseTest  | SecretManager, MFA, SSO, DeviceTracker, Sessions, Abuse       |
| AiFeatureTest           | Summaries, Action Items, Semantic Search, Suggestions, Config |
| **CallTest**            | **Call-Lifecycle, Signaling, Glare, History, Presence**       |
| **CallConcurrencyTest** | **Race Conditions, doppelte Initiierung, Lock-Sicherheit**    |

```powershell
# Nur Call-Tests
php vendor/bin/phpunit tests/Integration/CallTest.php tests/Integration/CallConcurrencyTest.php --testdox
```

**Frontend-Tests (Vitest):** 170 Tests — Messages (84: Policy, Actions, Toolbar, MobileMenu, MessageItem, InlineEditor), Calls (86: `useCall` Hook, `CallOverlay`, `CallEngine`)

```powershell
cd apps/chat-web
npx vitest run
```

---

## Dependencies

### `apps/chat-api` (PHP)

| Paket             | Version | Zweck            |
| ----------------- | ------- | ---------------- |
| `php`             | ≥ 8.2   | Laufzeitumgebung |
| `phpunit/phpunit` | ^11.0   | Test-Framework   |

> Kein externes Framework — Router, DI, Middleware, ORM vollständig selbst implementiert.

### `apps/chat-web` (React)

| Paket                       | Version | Zweck                         |
| --------------------------- | ------- | ----------------------------- |
| `react`                     | ^18.3.1 | UI-Framework                  |
| `react-dom`                 | ^18.3.1 | DOM-Rendering                 |
| `typescript`                | ^5.5.4  | Typsicherheit                 |
| `vite`                      | ^5.3.4  | Build-Tool + Dev-Server (HMR) |
| `vitest`                    | ^4.1.4  | Unit-Test-Framework           |
| `@testing-library/react`    | ^16.3.2 | Komponenten-Tests             |
| `@testing-library/jest-dom` | ^6.9.1  | DOM-Matcher                   |
| `@vitejs/plugin-react`      | ^4.3.1  | React-Plugin für Vite         |
| `jsdom`                     | ^28.1.0 | Browser-Simulation für Tests  |

> WebRTC (`RTCPeerConnection`, `getUserMedia`) — native Browser-API, keine externe Bibliothek.

### `apps/realtime` (Node.js WebSocket-Gateway)

| Paket    | Version | Zweck                              |
| -------- | ------- | ---------------------------------- |
| `ws`     | ^8.18.0 | WebSocket-Server                   |
| `redis`  | ^5.11.0 | Pub/Sub für horizontale Skalierung |
| `mysql2` | ^3.11.0 | DB-Verbindung für Auth-Validierung |
| `dotenv` | ^16.4.5 | Umgebungsvariablen                 |
| `tsx`    | ^4.19.0 | TypeScript-Ausführung (Dev)        |

### `apps/desktop` (Tauri)

| Paket   | Version | Zweck                            |
| ------- | ------- | -------------------------------- |
| Tauri 2 | ^2.x    | Desktop-Wrapper (Rust + WebView) |
