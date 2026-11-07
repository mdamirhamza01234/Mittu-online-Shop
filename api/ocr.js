/* Mittu Wholesaler - bill scan proxy (Gemini) for Vercel.
   Key sirf Vercel ke Environment Variable GEMINI_API_KEY mein rehti hai (website ke code mein nahi). */
const BASE = process.env.GEMINI_BASE || 'https://generativelanguage.googleapis.com/v1beta/';
const VBASE = process.env.GEMINI_VBASE || 'https://aiplatform.googleapis.com/v1/';
let mem = null;            /* {mode, model}: jis model ne jawab diya, jab tak instance garam hai yaad rahega */
let listCache = null;      /* {t, names} */
const hits = new Map();    /* ek IP se 30 scan / ghanta */

const SCHEMA = {
  type: 'OBJECT',
  properties: {
    customerName: { type: 'STRING' }, mobile: { type: 'STRING' }, address: { type: 'STRING' },
    items: { type: 'ARRAY', items: { type: 'OBJECT', properties: {
      line: { type: 'STRING' }, itemId: { type: 'STRING', nullable: true }, qty: { type: 'NUMBER' }, unit: { type: 'STRING' } },
      required: ['line', 'qty'] } },
  },
  required: ['items'],
};

function buildPrompt(items) {
  const lines = items.slice(0, 1500).filter(it => it && it.id != null && it.name).map(it =>
    String(it.id + ' | ' + it.name + ' | ' + (it.hindi || '') + ' | ' + (Array.isArray(it.units) ? it.units.join(' / ') : '')).replace(/[\n]/g, ' '));
  return "You read a customer's shopping list or bill (a photo or PDF; may be HANDWRITTEN; language may be Hindi in Devanagari, Hinglish or English) for an Indian grocery and general wholesale shop in Bihar.\n"
    + "Extract: customerName, mobile (10-digit Indian number, digits only), address (empty string if absent), and every item the customer wants.\n"
    + "For each item line choose the best matching stock item from the STOCK list below. Match by meaning, not spelling: Hindi, Hinglish and English names are the same item (e.g. आलू = Aalu = Potato, चीनी = Cheeni = Sugar, सरसों तेल = Sarson Tel = Mustard Oil).\n"
    + "Rules: itemId must be EXACTLY an id from STOCK, or null if nothing fits. unit must be EXACTLY one of that item's listed units. If the customer wrote another unit (gm, ग्राम, किलो, लीटर...) convert qty into one of the listed units (500 gm with unit kg => qty 0.5). If no unit is written use the first listed unit. qty is a number greater than 0 (default 1). line is the item text as written. Ignore prices, rates, totals and dates. Never invent items that are not written.\n\n"
    + "STOCK (id | name | hindi | units):\n" + lines.join('\n');
}

async function call(url, key, body, ms) {
  const ctl = new AbortController(); const to = setTimeout(() => ctl.abort(), ms);
  try {
    const r = await fetch(url, { method: body ? 'POST' : 'GET', headers: { 'Content-Type': 'application/json', 'x-goog-api-key': key }, body: body ? JSON.stringify(body) : undefined, signal: ctl.signal });
    let j = null; try { j = await r.json(); } catch (e) {}
    return { http: r.status, j, err: '' };
  } catch (e) { return { http: 0, j: null, err: String(e.message || e) }; }
  finally { clearTimeout(to); }
}

async function autoModels(key) {
  if (listCache && Date.now() - listCache.t < 864e5) return listCache.names;
  const r = await call(BASE + 'models?pageSize=200', key, null, 10000);
  const found = {};
  if (r.http === 200 && r.j && Array.isArray(r.j.models)) for (const m of r.j.models) {
    if (!m.name || !(m.supportedGenerationMethods || []).includes('generateContent')) continue;
    const n = m.name.replace(/^models\//, '');
    const mm = n.match(/^gemini-(\d+(?:\.\d+)?)-flash(-lite)?(-preview[\w.-]*)?$/);
    if (mm) found[n] = parseFloat(mm[1]) * 100 - (mm[2] ? 20 : 0) - (mm[3] ? 8 : 0);
  }
  const names = Object.keys(found).sort((a, b) => found[b] - found[a]);
  if (names.length) listCache = { t: Date.now(), names };
  return names;
}

async function candidates(mode, key) {
  const c = [];
  if (mem && mem.mode === mode) c.push(mem.model);
  if (process.env.GEMINI_MODEL) c.push(process.env.GEMINI_MODEL);
  if (mode === 'aistudio') { (await autoModels(key)).forEach(n => c.push(n)); c.push('gemini-flash-latest', 'gemini-2.5-flash', 'gemini-2.0-flash'); }
  else c.push('gemini-2.5-flash', 'gemini-2.0-flash', 'gemini-2.5-flash-lite', 'gemini-2.5-pro');
  return [...new Set(c)].slice(0, 6);
}

module.exports = async (req, res) => {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  res.setHeader('Cache-Control', 'no-store');
  if (req.method === 'OPTIONS') return res.status(204).end();
  if (req.method !== 'POST') return res.status(405).json({ ok: false, error: 'POST only' });
  const key = (process.env.GEMINI_API_KEY || '').trim();
  if (!key) return res.status(503).json({ ok: false, error: 'server key missing (Vercel Environment Variable GEMINI_API_KEY)' });

  let inp = req.body;
  if (typeof inp === 'string') { try { inp = JSON.parse(inp); } catch (e) { inp = null; } }
  if (!inp || typeof inp !== 'object') return res.status(400).json({ ok: false, error: 'bad request' });
  const { mime, data } = inp, items = Array.isArray(inp.items) ? inp.items : [];
  if (!['image/jpeg', 'image/png', 'image/webp', 'application/pdf'].includes(mime)) return res.status(400).json({ ok: false, error: 'bad type' });
  if (typeof data !== 'string' || !/^[A-Za-z0-9+/=\r\n]+$/.test(data)) return res.status(400).json({ ok: false, error: 'bad data' });

  const ip = String((req.headers['x-forwarded-for'] || '').split(',')[0] || 'x').trim(), now = Date.now();
  const h = (hits.get(ip) || []).filter(t => t > now - 36e5);
  if (h.length >= 30) return res.status(429).json({ ok: false, error: 'too many scans, thodi der baad try karein' });
  h.push(now); hits.set(ip, h); if (hits.size > 5000) hits.clear();

  const body = { contents: [{ role: 'user', parts: [{ text: buildPrompt(items) }, { inline_data: { mime_type: mime, data } }] }],
    generationConfig: { temperature: 0, responseMimeType: 'application/json', responseSchema: SCHEMA } };
  const modes = ['aistudio', 'vertex'];   /* AQ. aur AIza dono AI Studio (Gemini API) ki keys hain; Vertex sirf backup */
  const t0 = Date.now(), tried = []; let last = 'gemini error';
  for (const mode of modes) {
    let keyBad = false;
    for (const m of await candidates(mode, key)) {
      const left = 50000 - (Date.now() - t0); if (left < 4000) break;
      const url = mode === 'vertex' ? VBASE + 'publishers/google/models/' + m + ':generateContent' : BASE + 'models/' + m + ':generateContent';
      const r = await call(url, key, body, Math.min(25000, left));
      const isMem = mem && mem.mode === mode && mem.model === m;
      let msg;
      if (r.http === 0) { msg = 'network: ' + r.err; }
      else if (r.http === 200 && r.j && r.j.candidates && r.j.candidates[0] && r.j.candidates[0].content && r.j.candidates[0].content.parts && r.j.candidates[0].content.parts[0] && r.j.candidates[0].content.parts[0].text) {
        let parsed = null; try { parsed = JSON.parse(r.j.candidates[0].content.parts[0].text); } catch (e) {}
        if (parsed && typeof parsed === 'object') { mem = { mode, model: m }; return res.status(200).json({ ok: true, data: parsed, model: m, api: mode }); }
        msg = 'bad ai output';
      } else msg = (r.j && r.j.error && r.j.error.message) ? String(r.j.error.message).slice(0, 140) : 'no answer';
      last = mode + '/' + m + ' ' + r.http + ': ' + msg; tried.push([mode, m, r.http, msg]);
      if (isMem) mem = null;
      if (r.http === 401 || (r.http === 400 && /api key|API_KEY/i.test(msg))) { keyBad = true; break; }
    }
    if (!keyBad) break;
  }
  return res.status(502).json({ ok: false, error: last, tried });
};
module.exports.config = { api: { bodyParser: { sizeLimit: '6mb' } } };
