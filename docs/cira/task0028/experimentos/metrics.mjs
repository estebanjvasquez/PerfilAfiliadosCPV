import fs from 'node:fs';
const R = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const live = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
const EXP = ['lexical_experiencia', 'experiencia'];
const ids = (l) => l.map((x) => x.id);
function expShare(r, id) {
  const k = r.contributions[id]; if (!k) return null;
  const a = r.a_contributions[id] || {};
  let tot = 0, exp = 0;
  for (const [l, v] of Object.entries(k)) { tot += v; if (EXP.includes(l)) exp += v; else if (l === 'fulltext' && !a.fulltext) exp += v; }
  return tot ? exp / tot : null;
}
const judged = (top, g) => ({ rel: top.filter((i) => g.rel.includes(i)).length, norel: top.filter((i) => g.norel.includes(i)).length, unk: top.filter((i) => !g.rel.includes(i) && !g.norel.includes(i)).length });
const rows = [];
const agg = { cases: R.length, golden: 0, fidelity_same_order: 0, fidelity_same_top5: 0, duplicated_phrase_cases: 0, dedup_top5_changed: 0 };
const V = ['actual', 'A', 'Btotal'];
const sum = {}; for (const v of V) sum[v] = { rel5: 0, norel5: 0, unk5: 0, relTop10: 0, relTotal: 0, results: 0, empty: 0 };
let expOnlyTop5 = 0, top5Count = 0; const omissions = [];
for (const r of R) {
  agg.fidelity_same_order += r.fidelity.same_order ? 1 : 0; agg.fidelity_same_top5 += r.fidelity.same_top5 ? 1 : 0;
  if (r.unique_phrase_count < r.phrase_count) agg.duplicated_phrase_cases++;
  if (JSON.stringify(ids(r.actual).slice(0, 5)) !== JSON.stringify(ids(r.dedup).slice(0, 5))) agg.dedup_top5_changed++;
  const lists = { actual: ids(r.actual), A: ids(r.A), Btotal: [...ids(r.B.group1), ...ids(r.B.group2)] };
  const top5exp = ids(r.actual).slice(0, 5).map((id) => ({ id, share: expShare(r, id) }));
  expOnlyTop5 += top5exp.filter((x) => x.share !== null && x.share >= 0.999).length; top5Count += top5exp.length;
  const row = { id: r.id, query: r.query, phrases: r.phrases.length, golden: r.golden?.test_id ?? '', intent: r.detected_intent, n_actual: r.actual.length, n_A: r.A.length, n_B1: r.B.group1.length, n_B2: r.B.group2.length,
    top5_actual: ids(r.actual).slice(0, 5), top5_A: ids(r.A).slice(0, 5), top5_B2: ids(r.B.group2).slice(0, 5), exp_only_in_actual_top5: top5exp.filter((x) => x.share >= 0.999).map((x) => x.id),
    exp_share_top5: top5exp.map((x) => `${x.id}:${x.share === null ? '?' : Math.round(x.share * 100)}%`), live_ms: live[r.id]?.total_ms ?? null, clone_ms: r.timings_ms };
  for (const v of V) { sum[v].results += lists[v].length; if (!lists[v].length) sum[v].empty++; }
  if (r.golden) {
    agg.golden++;
    for (const v of V) {
      const top5 = v === 'Btotal' ? ids(r.B.group1).slice(0, 5) : lists[v].slice(0, 5); // B: el grupo principal es el que se ve primero
      const j = judged(top5, r.golden); sum[v].rel5 += j.rel; sum[v].norel5 += j.norel; sum[v].unk5 += j.unk;
      const top10 = v === 'Btotal' ? [...ids(r.B.group1).slice(0, 10), ...ids(r.B.group2).slice(0, 10)] : lists[v].slice(0, 10);
      sum[v].relTop10 += r.golden.rel.filter((i) => top10.includes(i)).length; sum[v].relTotal += r.golden.rel.length;
      row['judged_' + v] = j;
    }
    const lost = r.golden.rel.filter((i) => lists.actual.slice(0, 10).includes(i) && !lists.A.slice(0, 10).includes(i));
    if (lost.length) omissions.push({ id: r.id, golden: r.golden.test_id, query: r.query, lost, recovered_in_B2: lost.filter((i) => ids(r.B.group2).includes(i)) });
    const noise = r.golden.norel.filter((i) => lists.actual.slice(0, 5).includes(i));
    row.norel_removed_by_A = noise.filter((i) => !lists.A.slice(0, 5).includes(i));
  }
  rows.push(row);
}
const out = { agg, sum, expOnlyTop5, top5Count, omissions, rows };
fs.writeFileSync(process.argv[4], JSON.stringify(out, null, 1));
console.log(JSON.stringify({ agg, sum, expOnlyTop5, top5Count, omissions }, null, 0));
for (const r of rows.filter((x) => /balanc|valvul|bomba|cabria/.test(x.query))) console.log(r.id, r.query, '| intent', r.intent, '| act', r.top5_actual.join(','), '| A', r.top5_A.join(','), '| B2', r.top5_B2.join(','), '| expShare', r.exp_share_top5.join(' '), r.golden ? '| ' + r.golden + ' ' + JSON.stringify(r.judged_actual) + '→A ' + JSON.stringify(r.judged_A) : '');
