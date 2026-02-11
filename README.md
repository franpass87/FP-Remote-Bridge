# FP Remote Bridge

Connettore per siti remoti che ricevono pubblicazioni e dati SEO da FP Publisher e altri prodotti dell'ecosistema FP.

## Descrizione

FP Remote Bridge va installato sui **siti remoti** (clienti, blog collegati) che ricevono contenuti da FP Publisher. Il plugin:

- Abilita i meta SEO nel REST API di WordPress (Yoast e FP SEO Manager)
- Espone l'endpoint `fp-publisher/v1/update-seo-meta` per il salvataggio da remoto
- Espone l'endpoint `fp-publisher/v1/update-plugins` per ricevere comandi di aggiornamento da hub esterni
- **Non richiede FP Updater** sul client: il Bridge installa gli aggiornamenti direttamente da GitHub (solo Bridge necessario)
- Non richiede FP Publisher sul sito remoto
- Funziona standalone

## Funzionalità

### 1. Meta SEO nel REST API

Registra i meta SEO con `show_in_rest => true` per permettere a FP Publisher di inviarli via REST API standard:

- **FP SEO Manager**: `_fp_seo_title`, `_fp_seo_meta_description`, `_fp_seo_focus_keyword`, ecc.
- **Yoast SEO**: `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_focuskw`

### 2. Endpoint custom

Se i meta vengono ignorati dalla REST API (es. Yoast non li espone), FP Publisher usa l'endpoint fallback:

```
POST /wp-json/fp-publisher/v1/update-seo-meta
```

Payload: `{ "post_id": 123, "meta": { "_yoast_wpseo_title": "..." } }`

### 3. Comunicazione con Master (sito centrale)

Il Bridge sul sito client contatta il Master (sito con FP Updater in modalità Master) per verificare aggiornamenti. Se ci sono, **il Bridge li installa direttamente** (nessun FP Updater necessario sul client). Configura: **Master**: FP Updater → tab Modalità Master → Abilita + Secret Client. **Client**: Impostazioni → FP Remote Bridge → URL Master + Secret Client. Token GitHub opzionale per repo privati. Polling periodico + pulsante "Sincronizza ora".

### 4. Aggiornamento remoto via POST (opzionale)

Se sul sito remoto sono installati **FP Remote Bridge** e **FP Updater**, puoi triggerare da un hub centrale il controllo e l’installazione di tutti gli aggiornamenti:

```
POST /wp-json/fp-publisher/v1/update-plugins
```

**Autenticazione:** header `X-FP-Update-Secret: <tuo_secret>` oppure body `secret=<tuo_secret>`.

Il secret si configura in **Impostazioni → FP Remote Bridge** (stesso sito remoto).

- **Solo controllo** (senza installare): `POST` con body `check_only=1`.
- **Controllo + installazione**: `POST` senza parametri (o senza `check_only`).

Esempio da terminale (aggiorna tutti i plugin su un sito remoto):

```bash
curl -X POST "https://sito-remoto.it/wp-json/fp-publisher/v1/update-plugins" \
  -H "X-FP-Update-Secret: IL_TUO_SECRET" \
  -H "Content-Type: application/json"
```

Risposta di successo (es.): `{ "success": true, "message": "Controllo e aggiornamento completati.", "pending_before": 2, "pending_after": 0, "updated": true }`.

### 5. Filtro Yoast

Applica `register_post_meta_args` per forzare `show_in_rest => true` sui meta Yoast già registrati.

## Installazione

### Requisiti

- WordPress 6.0+
- PHP 7.4+
- Yoast SEO o FP SEO Manager (opzionale, per gestire i meta SEO)

### Via FP Updater (consigliato)

1. Installa [FP Updater](https://github.com/franpass87/FP-Updater)
2. Vai in **FP Updater → Impostazioni** (o usalo per installare questo plugin da GitHub)
3. Inserisci: `franpass87/FP-Remote-Bridge`
4. Clicca **Install Plugin**
5. Attiva il plugin da **Plugins**

*(Non serve eseguire `composer install`: la cartella `vendor/` è inclusa nel repository.)*

### Installazione manuale

1. Scarica o clona il plugin in `wp-content/plugins/FP-Remote-Bridge/`
2. Esegui `composer install --no-dev` nella cartella del plugin
3. Attiva il plugin da **Plugins** in WordPress

### Composer

Se manca la cartella `vendor/`, il plugin mostrerà un avviso. Esegui:

```bash
cd wp-content/plugins/FP-Remote-Bridge
composer install --no-dev
```

## Compatibilità

| Prodotto         | Note                                                        |
|------------------|-------------------------------------------------------------|
| FP Publisher     | Usa meta REST e endpoint `update-seo-meta` automaticamente  |
| Yoast SEO        | Filtro abilita i meta nel REST                              |
| FP SEO Manager   | Meta `_fp_seo_*` registrati direttamente                    |

## Struttura

```
FP-Remote-Bridge/
├── fp-remote-bridge.php         # Main file
├── composer.json
├── includes/
│   ├── Plugin.php               # Classe principale
│   ├── SeoRest.php              # Meta SEO REST
│   ├── RestEndpoint.php         # Endpoint update-seo-meta
│   ├── PluginUpdateEndpoint.php # Endpoint update-plugins (POST esterno)
│   ├── PluginInstaller.php      # Install/update plugin da GitHub (senza FP Updater)
│   ├── MasterSync.php           # Polling Master, installa via PluginInstaller
│   └── Settings.php             # Impostazioni (Master + token GitHub + secret POST)
├── languages/
├── LICENSE
└── README.md
```

## Changelog

### 1.1.0 (previsto)

- **Comunicazione con Master**: il Bridge sul client effettua polling verso il Master; se ci sono aggiornamenti, **li installa direttamente** (senza bisogno di FP Updater sul client)
- Endpoint `POST /fp-publisher/v1/update-plugins` per ricevere comandi da hub esterno (opzionale)
- Pagina **Impostazioni → FP Remote Bridge** (URL Master, secret client, frequenza sync, secret POST)

### 1.0.0

- Release iniziale
- Registrazione meta SEO (FP SEO Manager + Yoast)
- Endpoint `/fp-publisher/v1/update-seo-meta`
- Filtro per Yoast `show_in_rest`

## Licenza

GPL v2 or later

## Autore

[Francesco Passeri](https://francescopasseri.com)
