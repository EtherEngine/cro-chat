# crø Desktop App (Tauri)

Native desktop wrapper for crø using Tauri 2.

## Voraussetzungen

- [Rust](https://rustup.rs/) (stable)
- [Node.js](https://nodejs.org/) ≥ 18
- Windows: Visual Studio Build Tools 2019+ mit C++ Workload

## Entwicklung

```powershell
# Im Desktop-Verzeichnis
cd apps/desktop
npm install

# Dev-Modus (startet Vite + Tauri gleichzeitig)
npm run dev
```

## Build

```powershell
npm run build
```

Die fertige `.exe` / `.msi` liegt unter `src-tauri/target/release/bundle/`.

## Features

- **Native Notifications** – über `tauri-plugin-notification`
- **Deep Links** – `web+cro://` Protokoll-Handler
- **Single Instance** – verhindert mehrfaches Öffnen
- **Auto-Update** (optional) – wird in zukünftiger Version aktiviert

## Architektur

```
apps/desktop/
  package.json          Node.js wrapper für Tauri CLI
  tauri.conf.json       Tauri-Konfiguration
  src-tauri/
    Cargo.toml          Rust-Dependencies
    src/main.rs         Tauri-Entry-Point
```

Das Frontend wird direkt von `apps/chat-web/` gebaut und eingebettet.
