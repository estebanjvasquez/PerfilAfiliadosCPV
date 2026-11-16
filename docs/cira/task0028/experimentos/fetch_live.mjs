// TASK-0028: aportes vectoriales vivos por /debug-search (solo lectura; no escribe cira_search_log). Secuencial.
import fs from 'node:fs';
const [casesFile, outFile] = process.argv.slice(2);
const cases = JSON.parse(fs.readFileSync(casesFile, 'utf8'));
const out = fs.existsSync(outFile) ? JSON.parse(fs.readFileSync(outFile, 'utf8')) : {};
for (const c of cases) {
  if (out[c.id]) continue;
  const t0 = Date.now();
  const r = await fetch('https://perfilafiliados-mcp.sisteg.workers.dev/debug-search', { method: 'POST', headers: { Authorization: 'Bearer ' + process.env.CPV_DEBUG_TOKEN, 'Content-Type': 'application/json' }, body: JSON.stringify({ query: c.query, resolvedPhrases: c.phrases }) });
  const j = await r.json();
  if (!r.ok) { console.error(c.id, r.status, JSON.stringify(j).slice(0, 200)); continue; }
  out[c.id] = { detected_intent: j.query_understanding?.detected_intent ?? null, total_ms: j.timings_ms?.total ?? null, wall_ms: Date.now() - t0, debug_id: j.debug_id,
    candidates: (j.candidates || []).map((x) => ({ empresa_id: Number(x.empresa_id), rank: x.rank, score: x.score, match_level: x.match_level, score_breakdown: x.score_breakdown })) };
  fs.writeFileSync(outFile, JSON.stringify(out));
  console.error(c.id, c.query, (j.candidates || []).length, 'cand', Date.now() - t0, 'ms');
}
