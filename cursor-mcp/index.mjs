import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';

const DEFAULT_SECTIONS = ['general', 'errors', 'performance', 'seo', 'client_js'];

function readSitesConfig() {
  const sites = [];

  if (process.env.FP_SITES_JSON) {
    try {
      const parsed = JSON.parse(process.env.FP_SITES_JSON);
      if (Array.isArray(parsed)) {
        for (const entry of parsed) {
          if (!entry || typeof entry !== 'object') {
            continue;
          }
          const siteUrl = String(entry.site_url || entry.url || '').trim();
          const secret = String(entry.secret || '').trim();
          const name = String(entry.name || siteUrl || 'site').trim();
          if (siteUrl && secret) {
            sites.push({ name, siteUrl, secret });
          }
        }
      }
    } catch (error) {
      throw new Error(`FP_SITES_JSON non valido: ${error.message}`);
    }
  }

  const siteUrl = String(process.env.FP_SITE_URL || '').trim();
  const secret = String(process.env.FP_BRIDGE_SECRET || '').trim();
  if (siteUrl && secret) {
    sites.push({
      name: String(process.env.FP_SITE_NAME || siteUrl).trim(),
      siteUrl,
      secret,
    });
  }

  if (sites.length === 0) {
    throw new Error('Configura FP_SITE_URL + FP_BRIDGE_SECRET oppure FP_SITES_JSON.');
  }

  return sites;
}

function resolveSite(sites, siteName) {
  if (!siteName) {
    return sites[0];
  }

  const match = sites.find((site) => site.name === siteName);
  if (!match) {
    throw new Error(`Sito "${siteName}" non trovato. Disponibili: ${sites.map((site) => site.name).join(', ')}`);
  }

  return match;
}

function buildEndpoint(siteUrl, sections, clientErrorsLimit) {
  const base = siteUrl.replace(/\/$/, '');
  const endpoint = new URL(`${base}/wp-json/fp-remote-bridge/v1/site-intelligence`);
  if (sections.length > 0) {
    endpoint.searchParams.set('sections', sections.join(','));
  }
  if (clientErrorsLimit > 0) {
    endpoint.searchParams.set('client_errors_limit', String(clientErrorsLimit));
  }
  return endpoint.toString();
}

async function fetchSiteIntelligence(site, sections, clientErrorsLimit) {
  const response = await fetch(buildEndpoint(site.siteUrl, sections, clientErrorsLimit), {
    method: 'GET',
    headers: {
      'X-FP-Client-Secret': site.secret,
      Accept: 'application/json',
    },
  });

  const bodyText = await response.text();
  let payload;
  try {
    payload = bodyText ? JSON.parse(bodyText) : {};
  } catch (error) {
    throw new Error(`Risposta non JSON da ${site.name}: ${bodyText.slice(0, 400)}`);
  }

  if (!response.ok) {
    const message = payload?.message || payload?.error || response.statusText;
    throw new Error(`HTTP ${response.status} da ${site.name}: ${message}`);
  }

  return payload;
}

const sites = readSitesConfig();
const server = new McpServer({
  name: 'fp-remote-bridge',
  version: '1.0.0',
});

server.tool(
  'fp_list_remote_sites',
  'Elenca i siti WordPress remoti configurati per FP Remote Bridge.',
  {},
  async () => ({
    content: [
      {
        type: 'text',
        text: JSON.stringify(
          sites.map((site) => ({
            name: site.name,
            site_url: site.siteUrl,
          })),
          null,
          2,
        ),
      },
    ],
  }),
);

server.tool(
  'fp_get_site_intelligence',
  'Legge lo snapshot diagnostico remoto (salute, log PHP, performance, SEO, errori JS/console).',
  {
    site_name: z.string().optional(),
    sections: z.array(z.enum(['general', 'errors', 'performance', 'seo', 'client_js'])).optional(),
    client_errors_limit: z.number().int().min(1).max(200).optional(),
  },
  async ({ site_name, sections, client_errors_limit }) => {
    const site = resolveSite(sites, site_name);
    const payload = await fetchSiteIntelligence(
      site,
      sections && sections.length > 0 ? sections : DEFAULT_SECTIONS,
      client_errors_limit || 50,
    );

    return {
      content: [
        {
          type: 'text',
          text: JSON.stringify(payload, null, 2),
        },
      ],
    };
  },
);

server.tool(
  'fp_get_client_js_errors',
  'Restituisce solo errori JavaScript, promise rejection e console.error raccolti dal browser.',
  {
    site_name: z.string().optional(),
    limit: z.number().int().min(1).max(200).optional(),
  },
  async ({ site_name, limit }) => {
    const site = resolveSite(sites, site_name);
    const payload = await fetchSiteIntelligence(site, ['client_js'], limit || 50);

    return {
      content: [
        {
          type: 'text',
          text: JSON.stringify(payload.client_js || payload, null, 2),
        },
      ],
    };
  },
);

const transport = new StdioServerTransport();
await server.connect(transport);
