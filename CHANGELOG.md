# Changelog

All notable changes to FP Remote Bridge will be documented in this file.

## [1.3.7] - 2026-03-09
### Changed
- Docs README

## [1.3.6] - 2026-03-07
### Fixed
- Protezione cartella backup temp tramite `.htaccess`
- Cleanup backup eseguito solo se upload riuscito
- `check_permission` rinominato in `permission_check` su 4 endpoint REST

## [1.3.3] - 2026-03-07
### Added
- Endpoint `/plugin-versions` per aggiornamento versioni da Master in tempo reale

### Fixed
- Invio `installed_plugins` al Master via POST (nessun limite URL)

## [1.3.0] - 2026-03-06
### Added
- Endpoint `/reload`: deattiva e riattiva il Bridge per forzare ricaricamento dopo aggiornamento

## [1.2.9] - 2026-03-06
### Added
- Salvataggio versione disco/memoria nel DB dopo installazione
- Endpoint `/install-log` per debug versione

## [1.2.8] - 2026-03-06
### Fixed
- `muplugins_loaded` aggiorna `active_plugins` se Bridge installato in cartella alternativa

## [1.2.5] - 2026-03-05
### Fixed
- `upgrade_self` installa in cartella alternativa per bypassare opcache

## [1.2.4] - 2026-03-05
### Added
- Endpoint `/flush-cache` per invalidare opcache dopo aggiornamento Bridge

## [1.2.3] - 2026-03-05
### Added
- Endpoint `/status` diagnostico

## [1.2.0] - 2026-03-05
### Fixed
- `cleanup_duplicate_dirs` aggiorna `active_plugins` alla versione più alta

## [1.1.3] - 2026-03-05
### Added
- Dopo installazione ri-pinga Master con versioni aggiornate (UI in tempo reale)

## [1.1.2] - 2026-03-05
### Added
- Endpoint `trigger-sync`: Master chiama client in push al deploy

## [1.1.0] - 2026-03-05
### Added
- Pulizia automatica cartelle duplicate case-insensitive al primo avvio
- Attivazione automatica plugin dopo installazione

## [1.0.0] - 2026-02-04
### Added
- Release iniziale: connettore per siti remoti
- Riceve pubblicazioni e dati SEO da FP Publisher e altri prodotti FP
- Integrazione con FP Updater per aggiornamenti automatici
