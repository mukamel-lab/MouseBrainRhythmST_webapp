const API_SCRIPT = new URL('api/index.php', document.baseURI);

export function asArray(value) {
  if (value === undefined || value === null) return [];
  return Array.isArray(value) ? value.map(String) : [String(value)];
}

export function buildQuery(params = {}) {
  const sp = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value === null || value === undefined) continue;
    if (Array.isArray(value)) sp.set(key, value.join(','));
    else sp.set(key, String(value));
  }
  return sp;
}

export function apiUrl(path, params = {}) {
  const url = new URL(API_SCRIPT);
  url.searchParams.set('route', String(path || '').replace(/^\/+|\/+$/g, ''));
  const query = buildQuery(params);
  for (const [key, value] of query.entries()) url.searchParams.set(key, value);
  return url.toString();
}

async function parseError(response) {
  const text = await response.text();
  try {
    const payload = JSON.parse(text);
    if (payload.error?.details) return `${payload.error.message}: ${payload.error.details}`;
    if (payload.error?.message) return payload.error.message;
  } catch {
    // fall through
  }
  return text || `${response.status} ${response.statusText}`;
}

export async function fetchJson(path, params = {}, signal) {
  const response = await fetch(apiUrl(path, params), {
    method: 'GET',
    headers: { Accept: 'application/json' },
    signal,
  });
  if (!response.ok) throw new Error(await parseError(response));
  return response.json();
}

export async function fetchDiurnalPlot(params, signal) {
  return fetchJson('/plot-data', params, signal);
}

export async function fetchGenes(query, signal, limit = 80) {
  const payload = await fetchJson('/genes', { q: query ?? '', limit }, signal);
  return asArray(payload.genes ?? payload);
}

export async function resolveGene(query, signal) {
  const payload = await fetchJson('/genes/resolve', { q: query ?? '', limit: 25 }, signal);
  return {
    ...payload,
    gene: payload.gene === null || payload.gene === undefined ? '' : String(payload.gene),
    suggestions: asArray(payload.suggestions),
  };
}

export async function fetchRhythmicity(params, signal) {
  return fetchJson('/rhythmicity', params, signal);
}

export async function fetchRhythmicityBasic(params, signal) {
  return fetchJson('/rhythmicity/basic', params, signal);
}

export async function fetchAllenIsh(params, signal) {
  return fetchJson('/allen/ish', params, signal);
}

export async function fetchHippocampusDv(params, signal) {
  return fetchJson('/hippocampus-dv', params, signal);
}

export async function fetchHippocampusDvGenes(query, signal, limit = 80) {
  const payload = await fetchJson('/hippocampus-dv/genes', { q: query ?? '', limit }, signal);
  return asArray(payload.genes ?? payload);
}

export async function fetchRostralCaudal(params, signal) {
  return fetchJson('/rostral-caudal', params, signal);
}

export async function fetchRostralCaudalGenes(query, signal, limit = 80) {
  const payload = await fetchJson('/rostral-caudal/genes', { q: query ?? '', limit }, signal);
  return asArray(payload.genes ?? payload);
}
