# FP Remote Bridge

Connettore per siti remoti che ricevono pubblicazioni, dati SEO e aggiornamenti plugin dall'ecosistema FP (FP Publisher, FP Updater).

[![Version](https://img.shields.io/badge/version-1.3.6-blue.svg)](https://github.com/franpass87/FP-Remote-Bridge)
[![License](https://img.shields.io/badge/license-Proprietary-red.svg)]()

---

## Per l'utente

### Cosa fa
FP Remote Bridge va installato sui **siti client** (siti remoti). Permette al sito Master (dove gira FP Updater) di:
- Installare e aggiornare plugin da remoto
- Sincronizzare le versioni installate
- Ricevere pubblicazioni da FP Publisher
- Auto-aggiornarsi tramite FP Updater

### Installazione
1. Scarica il plugin e installalo sul sito client
2. Vai su **Impostazioni → FP Remote Bridge**
3. Inserisci il **Client ID** e il **Secret** forniti dal sito Master
4. Salva e verifica la connessione

### Requisiti
- WordPress 6.0+
- PHP 8.0+
- FP Updater sul sito Master

---

## Per lo sviluppatore

### Struttura
```
FP-Remote-Bridge/
├── fp-remote-bridge.php        # File principale
├── src/
│   ├── Core/Plugin.php         # Bootstrap plugin
│   ├── REST/
│   │   ├── InstallEndpoint.php # /install - installa plugin
│   │   ├── StatusEndpoint.php  # /status - diagnostica
│   │   ├── ReloadEndpoint.php  # /reload - ricarica Bridge
│   │   ├── FlushCacheEndpoint.php # /flush-cache - invalida opcache
│   │   ├── PluginVersions.php  # /plugin-versions - versioni installate
│   │   └── TriggerSync.php     # /trigger-sync - sync da Master
│   ├── Installer/
│   │   └── PluginInstaller.php # Installazione/aggiornamento plugin
│   ├── Backup/
│   │   └── BackupManager.php   # Gestione backup prima dell'update
│   └── Auth/
│       └── AuthMiddleware.php  # Autenticazione client_id/secret
└── vendor/
```

### REST Endpoints
| Endpoint | Metodo | Auth | Descrizione |
|----------|--------|------|-------------|
| `/wp-json/fp-bridge/v1/status` | GET | App Password | Stato diagnostico Bridge |
| `/wp-json/fp-bridge/v1/install` | POST | App Password | Installa/aggiorna un plugin |
| `/wp-json/fp-bridge/v1/plugin-versions` | POST | App Password | Versioni plugin installati |
| `/wp-json/fp-bridge/v1/trigger-sync` | POST | App Password | Forza sync da Master |
| `/wp-json/fp-bridge/v1/reload` | POST | App Password | Ricarica Bridge (deattiva+riattiva) |
| `/wp-json/fp-bridge/v1/flush-cache` | POST | App Password | Invalida opcache |
| `/wp-json/fp-bridge/v1/install-log` | GET | App Password | Log ultima installazione |

### Autenticazione
Tutti gli endpoint usano **WordPress Application Passwords**. Il Master configura le credenziali nel pannello FP Updater.

### Auto-aggiornamento
Il Bridge può aggiornarsi da solo tramite l'endpoint `/install`. Per bypassare problemi di opcache, installa in una cartella alternativa e usa `/reload` per riattivare.

---

## Changelog
Vedi [CHANGELOG.md](CHANGELOG.md)
