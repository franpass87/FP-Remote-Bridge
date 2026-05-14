import { readFile, writeFile } from 'node:fs/promises';
import { homedir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const SCRIPT_DIR = dirname(fileURLToPath(import.meta.url));
const DEFAULT_MANAGER_URL = 'https://manager.francescopasseri.com';
const DEFAULT_MCP_CONFIG_PATH = join(homedir(), '.cursor', 'mcp.json');
const DEFAULT_ENV_CANDIDATES = [
  join(SCRIPT_DIR, '.env.local'),
  'C:/Users/franc/Local Sites/fp-development/app/public/.env.local',
];
const MCP_SERVER_KEY = 'fp-remote-bridge';
const MCP_INDEX_PATH = 'C:/Users/franc/OneDrive/Desktop/FP-Remote-Bridge/cursor-mcp/index.mjs';

function parseArgs(argv) {
  return {
    dryRun: argv.includes('--dry-run'),
    help: argv.includes('--help') || argv.includes('-h'),
  };
}

function printHelp() {
  console.log(`Sincronizza ~/.cursor/mcp.json con i client FP Remote Bridge del Master FP Updater.

Uso:
  node sync-mcp-from-manager.mjs [--dry-run]

Variabili:
  FP_MANAGER_URL           URL del Master (default: ${DEFAULT_MANAGER_URL})
  FP_BRIDGE_SECRET         Secret Master (stesso valore sui client Bridge)
  FP_MCP_CONFIG_PATH       Percorso mcp.json (default: ${DEFAULT_MCP_CONFIG_PATH})
  FP_MCP_EXTRA_SITES_JSON  Siti extra da mantenere (es. fp-development.local)
  FP_ENV_FILE              .env locale opzionale con FP_BRIDGE_SECRET

Dopo la sync, riavvia Cursor per ricaricare il server MCP.`);
}

function normalizeSiteUrl(siteUrl) {
  return String(siteUrl || '').trim().replace(/\/+$/, '').toLowerCase();
}

async function loadEnvFile(filePath) {
  const content = await readFile(filePath, 'utf8');
  for (const rawLine of content.split(/\r?\n/)) {
    const line = rawLine.trim();
    if (line === '' || line.startsWith('#')) {
      continue;
    }

    const separator = line.indexOf('=');
    if (separator <= 0) {
      continue;
    }

    const key = line.slice(0, separator).trim();
    let value = line.slice(separator + 1).trim();
    if (
      (value.startsWith('"') && value.endsWith('"'))
      || (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1);
    }

    if (process.env[key] === undefined) {
      process.env[key] = value;
    }
  }
}

async function loadOptionalEnvFiles() {
  const candidates = [
    process.env.FP_ENV_FILE,
    ...DEFAULT_ENV_CANDIDATES,
  ].filter(Boolean);

  for (const candidate of candidates) {
    try {
      await loadEnvFile(resolve(candidate));
    } catch {
      // File opzionale assente o non leggibile.
    }
  }
}

function parseExtraSites(rawValue) {
  if (!rawValue) {
    return [];
  }

  let parsed;
  try {
    parsed = JSON.parse(rawValue);
  } catch (error) {
    throw new Error(`FP_MCP_EXTRA_SITES_JSON non valido: ${error.message}`);
  }

  if (!Array.isArray(parsed)) {
    throw new Error('FP_MCP_EXTRA_SITES_JSON deve essere un array JSON.');
  }

  const sites = [];
  for (const entry of parsed) {
    if (!entry || typeof entry !== 'object') {
      continue;
    }

    const siteUrl = String(entry.site_url || entry.url || '').trim();
    const secret = String(entry.secret || '').trim();
    const name = String(entry.name || siteUrl || 'site').trim();
    if (!siteUrl || !secret) {
      continue;
    }

    sites.push({
      name,
      site_url: siteUrl.replace(/\/+$/, ''),
      secret,
    });
  }

  return sites;
}

async function readJsonFile(filePath) {
  const content = await readFile(filePath, 'utf8');
  return JSON.parse(content);
}

async function readExistingMcpConfig(filePath) {
  try {
    return await readJsonFile(filePath);
  } catch {
    return { mcpServers: {} };
  }
}

function getExistingBridgeSites(config) {
  const server = config?.mcpServers?.[MCP_SERVER_KEY];
  const raw = server?.env?.FP_SITES_JSON;
  if (!raw) {
    return [];
  }

  let parsed;
  try {
    parsed = JSON.parse(raw);
  } catch {
    return [];
  }

  if (!Array.isArray(parsed)) {
    return [];
  }

  return parsed
    .filter((entry) => entry && typeof entry === 'object')
    .map((entry) => ({
      name: String(entry.name || entry.site_url || '').trim(),
      site_url: String(entry.site_url || entry.url || '').trim().replace(/\/+$/, ''),
      secret: String(entry.secret || '').trim(),
    }))
    .filter((entry) => entry.site_url && entry.secret);
}

async function fetchManagerSites(managerUrl, secret) {
  const endpoint = `${managerUrl.replace(/\/+$/, '')}/wp-json/fp-git-updater/v1/cursor-mcp-sites`;
  const response = await fetch(endpoint, {
    method: 'GET',
    headers: {
      'X-FP-Client-Secret': secret,
      Accept: 'application/json',
    },
  });

  const bodyText = await response.text();
  let payload;
  try {
    payload = bodyText ? JSON.parse(bodyText) : {};
  } catch {
    throw new Error(`Risposta non JSON dal Master: ${bodyText.slice(0, 400)}`);
  }

  if (!response.ok) {
    const message = payload?.message || payload?.error || response.statusText;
    throw new Error(`HTTP ${response.status} dal Master: ${message}`);
  }

  if (!Array.isArray(payload?.sites)) {
    throw new Error('Risposta Master senza elenco sites.');
  }

  return payload.sites;
}

function buildSites(managerSites, existingSites, extraSites, masterSecret) {
  const secretByUrl = new Map(
    existingSites.map((site) => [normalizeSiteUrl(site.site_url), site.secret]),
  );

  const merged = [];
  const seen = new Set();

  for (const site of managerSites) {
    const siteUrl = String(site.site_url || '').trim().replace(/\/+$/, '');
    if (!siteUrl) {
      continue;
    }

    const key = normalizeSiteUrl(siteUrl);
    if (seen.has(key)) {
      continue;
    }
    seen.add(key);

    merged.push({
      name: String(site.name || site.client_id || siteUrl).trim(),
      site_url: siteUrl,
      secret: secretByUrl.get(key) || masterSecret,
    });
  }

  for (const site of extraSites) {
    const key = normalizeSiteUrl(site.site_url);
    if (seen.has(key)) {
      continue;
    }
    seen.add(key);
    merged.push(site);
  }

  merged.sort((a, b) => a.name.localeCompare(b.name, 'it', { sensitivity: 'base' }));
  return merged;
}

function buildMcpConfig(existingConfig, sites) {
  const nextConfig = {
    ...existingConfig,
    mcpServers: {
      ...(existingConfig.mcpServers || {}),
      [MCP_SERVER_KEY]: {
        command: 'node',
        args: [MCP_INDEX_PATH],
        env: {
          ...(existingConfig.mcpServers?.[MCP_SERVER_KEY]?.env || {}),
          FP_SITES_JSON: JSON.stringify(sites),
        },
      },
    },
  };

  return nextConfig;
}

async function main() {
  const args = parseArgs(process.argv.slice(2));
  if (args.help) {
    printHelp();
    return;
  }

  await loadOptionalEnvFiles();

  const managerUrl = String(process.env.FP_MANAGER_URL || DEFAULT_MANAGER_URL).trim();
  const masterSecret = String(process.env.FP_BRIDGE_SECRET || process.env.FP_MCP_MASTER_SECRET || '').trim();
  const configPath = resolve(process.env.FP_MCP_CONFIG_PATH || DEFAULT_MCP_CONFIG_PATH);
  const extraSites = parseExtraSites(process.env.FP_MCP_EXTRA_SITES_JSON);

  if (!masterSecret) {
    throw new Error('Imposta FP_BRIDGE_SECRET (o FP_MCP_MASTER_SECRET) con la chiave Master FP Updater.');
  }

  const existingConfig = await readExistingMcpConfig(configPath);
  const existingSites = getExistingBridgeSites(existingConfig);
  const managerSites = await fetchManagerSites(managerUrl, masterSecret);
  const sites = buildSites(managerSites, existingSites, extraSites, masterSecret);
  const nextConfig = buildMcpConfig(existingConfig, sites);

  if (args.dryRun) {
    console.log(JSON.stringify({
      manager_url: managerUrl,
      mcp_config_path: configPath,
      manager_sites: managerSites.length,
      extra_sites: extraSites.length,
      sites,
    }, null, 2));
    return;
  }

  await writeFile(configPath, `${JSON.stringify(nextConfig, null, 2)}\n`, 'utf8');
  console.log(`Aggiornato ${configPath} con ${sites.length} siti MCP.`);
}

main().catch((error) => {
  console.error(error.message);
  process.exit(1);
});
