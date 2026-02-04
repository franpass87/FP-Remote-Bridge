# FP Remote Bridge

Connettore per siti remoti che ricevono pubblicazioni e dati SEO da FP Publisher e altri prodotti dell'ecosistema FP.

## Descrizione

FP Remote Bridge va installato sui **siti remoti** (clienti, blog collegati) che ricevono contenuti da FP Publisher. Il plugin:

- Abilita i meta SEO nel REST API di WordPress (Yoast e FP SEO Manager)
- Espone l'endpoint `fp-publisher/v1/update-seo-meta` per il salvataggio da remoto
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

### 3. Filtro Yoast

Applica `register_post_meta_args` per forzare `show_in_rest => true` sui meta Yoast già registrati.

## Installazione

### Requisiti

- WordPress 6.0+
- PHP 7.4+
- Yoast SEO o FP SEO Manager (opzionale, per gestire i meta SEO)

### Passi

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
├── fp-remote-bridge.php    # Main file
├── composer.json
├── includes/
│   ├── Plugin.php          # Classe principale
│   ├── SeoRest.php         # Meta SEO REST
│   └── RestEndpoint.php    # Endpoint update-seo-meta
├── languages/
├── LICENSE
└── README.md
```

## Changelog

### 1.0.0

- Release iniziale
- Registrazione meta SEO (FP SEO Manager + Yoast)
- Endpoint `/fp-publisher/v1/update-seo-meta`
- Filtro per Yoast `show_in_rest`

## Licenza

GPL v2 or later

## Autore

[Francesco Passeri](https://francescopasseri.com)
