# Changelog

All notable changes to FP Remote Bridge will be documented in this file.

## [1.7.3] - 2026-05-14
### Fixed
- `sync-mcp-from-manager.mjs`: accetta anche un singolo oggetto in `FP_MCP_EXTRA_SITES_JSON`.

## [1.7.2] - 2026-05-14
### Added
- Script `cursor-mcp/sync-mcp-from-manager.mjs` per allineare `~/.cursor/mcp.json` ai client FP Remote Bridge del Master FP Updater.

## [1.7.1] - 2026-05-13
### Added
- Report read-only `fp_ml_gaps` in `site-reports` per contenuti senza traduzione inglese gestita da FP Multilanguage (`_fpml_pair_id_en` / legacy `_fpml_pair_id`).

## [1.7.0] - 2026-05-13
### Added
- Endpoint read-only `GET /wp-json/fp-remote-bridge/v1/site-reports` con report mirati `seo_gaps` e `wpml_gaps` (meta SEO mancanti e contenuti senza traduzione inglese WPML).
- Tool MCP `fp_get_site_reports` per leggere i report da Cursor.

## [1.6.1] - 2026-05-13
### Fixed
- `site-intelligence` / diagnostica performance: segnali FP Performance letti dalle opzioni correnti (`fp_ps_page_cache_settings`, `fp_ps_assets`, `fp_ps_lazy_load`, `fp_ps_external_cache`, ecc.) con fallback legacy, invece dei flag obsoleti sempre falsi.

## [1.6.0] - 2026-05-13
### Added
- Pagina admin **Impostazioni → FP Bridge Diagnostica** con KPI, errori JS/console, segnali SEO, performance FP e log PHP.
- Aggiornamento AJAX della panoramica diagnostica senza ricaricare la pagina.

## [1.5.0] - 2026-05-13
### Added
- Endpoint read-only `GET /wp-json/fp-remote-bridge/v1/site-intelligence` per diagnostica remota (salute sito, log PHP, performance, SEO, errori browser).
- Raccolta errori JavaScript, promise rejection e `console.error` da frontend e admin con ingest AJAX rate-limited.
- Sezione impostazioni **Diagnostica remota (Cursor)** e server MCP di esempio in `cursor-mcp/`.

## [1.4.2] - 2026-04-05
### Fixed
- **marketing-metrics**: `normalizeDateKey()` non rielabora più le chiavi già in formato `Y-m-d` (Bio/CTA). Con fuso orario del server divers da UTC, `strtotime`+`gmdate` poteva spostare il giorno di un’unità e i clic risultavano **fuori periodo** nel report DMS (spesso 0).

## [1.4.1] - 2026-03-24
### Fixed
- Endpoint `marketing-metrics`: normalizzata la lettura delle opzioni CTA/Bio evitando doppie chiamate a `get_option()` e migliorando la robustezza del payload.

## [1.4.0] - 2026-03-24
### Added
- Nuovo endpoint autenticato `GET /wp-json/fp-remote-bridge/v1/marketing-metrics` per esporre metriche marketing aggregate verso FP DMS.

### Changed
- Payload bridge esteso con `plugins`, `daily`, `totals` e `updated_at` per supportare KPI CTA/Bio con granularita giornaliera.

## [1.3.11] - 2026-03-23
### Changed
- Grafica unificata pagina impostazioni (header FP, h1 screen-reader-text)
- CSS inline spostato in `assets/css/admin.css`

### Added
- Enqueue `admin.css` sulla pagina impostazioni

## [1.3.10] - 2026-03-21
### Added
- Endpoint `POST /wp-json/fp-publisher/v1/wpml-link-translation` per collegare traduzioni WPML all'originale. Usato da FP-Publisher dopo la creazione di un post traduzione per associarlo all'articolo originale tramite `wpml_set_element_language_details`. Parametri: `original_id`, `translation_id`, `language_code`, `post_type` (opzionale).

## [1.3.9] - 2026-03-19
### Added
- Header `X-FP-Site-Name` inviato al Master (nome sito da Impostazioni > Generale)
- Campo `site_name` nella risposta endpoint `/plugin-versions`

## [1.3.8] - 2026-03-12
### Fixed
- Il cron non installa più automaticamente: aggiornamenti solo via trigger-sync (push dal Master) o "Sincronizza ora" manuale

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
