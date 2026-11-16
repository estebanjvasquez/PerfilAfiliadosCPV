// TASK-0028: arnés de experimentos aislados (Actual / A / B) con el código exacto del Worker 77ce273.
// Corre contra el clon cpvt28-db (sin tráfico real). Las listas vectoriales no se pueden recalcular sin el
// token de /embed: se inyectan los aportes vectoriales que devolvió /debug-search (solo lectura, sin registro).
import postgres from 'postgres';
import fs from 'node:fs';
import {
	computePhraseEvidence,
	mergePhraseEvidence,
	rankedIds,
	countDirectCompanies,
	applyTextOnlyGate,
	LIST_WEIGHTS,
	LIST_TIER,
	RRF_K,
	TIER_NAME,
	type EvidenceList,
	type Evidence,
	type FusedEvidence,
	type EvidenceTier,
} from './mcp/src/hybrid-search';

type Case = { id: string; query: string; phrases: string[]; source: string; golden?: { test_id: string; rel: number[]; norel: number[] } };
type LiveCand = { empresa_id: number; rank: number; score: number; match_level: string; score_breakdown: Record<string, number> };
type Live = { detected_intent: string; candidates: LiveCand[]; total_ms: number };

const sql = postgres(process.env.CLONE_URL!, { max: 4, fetch_types: false });
const env: any = { AI: { run: async () => ({ data: [] }) } }; // sin embeddings: las listas vectoriales salen de /debug-search
const cases: Case[] = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const live: Record<string, Live> = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));

const VECTOR_LISTS: EvidenceList[] = ['service', 'taxonomy', 'experiencia'];
const EXPERIENCE_LISTS: EvidenceList[] = ['lexical_experiencia', 'experiencia'];
const norm = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

function fuse(lists: Partial<Record<EvidenceList, Evidence[]>>) {
	const result = new Map<number, FusedEvidence>();
	const contrib = new Map<number, Partial<Record<EvidenceList, number>>>();
	for (const [list, rows] of Object.entries(lists) as [EvidenceList, Evidence[]][]) {
		const seen = new Set<number>();
		let rank = 0;
		for (const row of rows) {
			const id = Number(row.empresa_id);
			if (seen.has(id)) continue;
			seen.add(id);
			rank++;
			const c = LIST_WEIGHTS[list] / (RRF_K + rank);
			const k = contrib.get(id) ?? {};
			k[list] = (k[list] ?? 0) + c;
			contrib.set(id, k);
			const e = result.get(id);
			const tier = LIST_TIER[list];
			result.set(id, e ? { ...e, score: e.score + c, tier: Math.min(e.tier, tier) as EvidenceTier } : { score: c, matchType: '', matchedVia: '', tier, viaWeight: LIST_WEIGHTS[list] });
		}
	}
	return { result, contrib };
}

function vectorMap(lv: Live, keep: EvidenceList[], scale = 1) {
	const result = new Map<number, FusedEvidence>();
	const contrib = new Map<number, Partial<Record<EvidenceList, number>>>();
	for (const c of lv.candidates) {
		for (const list of keep) {
			const v = c.score_breakdown[list];
			if (!v) continue;
			const id = Number(c.empresa_id);
			const k = contrib.get(id) ?? {};
			k[list] = (k[list] ?? 0) + v * scale;
			contrib.set(id, k);
			const e = result.get(id);
			const tier = LIST_TIER[list];
			result.set(id, e ? { ...e, score: e.score + v * scale, tier: Math.min(e.tier, tier) as EvidenceTier } : { score: v * scale, matchType: '', matchedVia: '', tier, viaWeight: LIST_WEIGHTS[list] });
		}
	}
	return { result, contrib };
}

function mergeContrib(parts: Map<number, Partial<Record<EvidenceList, number>>>[]) {
	const out = new Map<number, Partial<Record<EvidenceList, number>>>();
	for (const p of parts) for (const [id, k] of p) {
		const o = out.get(id) ?? {};
		for (const [l, v] of Object.entries(k) as [EvidenceList, number][]) o[l] = (o[l] ?? 0) + v;
		out.set(id, o);
	}
	return out;
}

async function fullTextFrom(table: string, phrase: string): Promise<Evidence[]> {
	const rows = await sql<{ empresa_id: number }[]>`
		select esd.empresa_id from ${sql(table)} esd, websearch_to_tsquery('spanish', unaccent(${phrase})) q
		where esd.search_vector @@ q order by ts_rank_cd(esd.search_vector, q, 1) desc limit 30`;
	return rows.map((r) => ({ empresa_id: Number(r.empresa_id), label: `texto (${table})` }));
}

// Misma consulta que lexicalExperienciaList del Worker (sin ORDER BY, limit 20): lista previa a las compuertas.
async function lexicalExperienciaRaw(phrase: string): Promise<Evidence[]> {
	const rows = await sql<{ empresa_id: number }[]>`
		select empresa_id from empresa_experiencias where unaccent(descripcion) ilike unaccent(${'%' + phrase + '%'}) limit 20`;
	return rows.map((r) => ({ empresa_id: Number(r.empresa_id), label: 'experiencia' }));
}

const active = new Set((await sql<{ id: number }[]>`select id from empresas where status_id = 1`).map((r) => Number(r.id)));

function finalize(ranked: { empresa_id: number; score: number; tier: EvidenceTier }[], limit: number) {
	return ranked.filter((r) => active.has(Number(r.empresa_id))).slice(0, limit).map((r) => ({ id: Number(r.empresa_id), score: Math.round(r.score * 10000) / 10000, level: TIER_NAME[r.tier] }));
}

const out: any[] = [];
for (const c of cases) {
	const lv = live[c.id];
	const phrases = Array.from(new Set([c.query, ...c.phrases]));
	const t0 = performance.now();
	const details = await Promise.all(phrases.map((p) => computePhraseEvidence(sql, env, p)));
	const tAct = performance.now() - t0;
	const direct = countDirectCompanies(details[0].namedLists);
	const limit = direct > 20 ? 150 : 20;

	// ACTUAL: listas del clon (con sus compuertas) + aportes vectoriales vivos.
	const actFused = details.map((d) => fuse(d.namedLists));
	const actVec = vectorMap(lv, VECTOR_LISTS);
	const actual = finalize(rankedIds(mergePhraseEvidence([...actFused.map((f) => f.result), actVec.result])), limit);
	const actContrib = mergeContrib([...actFused.map((f) => f.contrib), actVec.contrib]);

	// ACTUAL sin doble cómputo: frases deduplicadas sin tildes ni mayúsculas (vectorial escalado, aproximación).
	const uniq = Array.from(new Map(phrases.map((p, i) => [norm(p), i])).values());
	const dedupVec = vectorMap(lv, VECTOR_LISTS, uniq.length / phrases.length);
	const dedup = finalize(rankedIds(mergePhraseEvidence([...uniq.map((i) => actFused[i].result), dedupVec.result])), limit);

	// A: sin experiencias en generación, ranking, fulltext ni embeddings.
	const t1 = performance.now();
	const aLists: Partial<Record<EvidenceList, Evidence[]>>[] = [];
	for (let i = 0; i < phrases.length; i++) {
		const l = { ...details[i].namedLists } as Record<EvidenceList, Evidence[]>;
		l.lexical_experiencia = [];
		l.experiencia = [];
		l.fulltext = await fullTextFrom('esd_noexp', phrases[i]);
		applyTextOnlyGate(l);
		aLists.push(l);
	}
	const tA = performance.now() - t1 + tAct;
	const aFused = aLists.map((l) => fuse(l));
	const aVec = vectorMap(lv, ['service', 'taxonomy']);
	let aMerged = mergePhraseEvidence([...aFused.map((f) => f.result), aVec.result]);
	const aContrib = mergeContrib([...aFused.map((f) => f.contrib), aVec.contrib]);
	// Compuerta «similar a» única, re-evaluada sin experiencias (agregada por empresa: aproximación de la del Worker, que es por frase).
	const strong = [...aContrib.entries()].filter(([, k]) => Object.keys(k).some((l) => !['service', 'taxonomy'].includes(l)));
	if (strong.length > 0) {
		for (const [id, k] of aContrib) {
			const ls = Object.keys(k);
			if (ls.length === 1 && ['service', 'taxonomy'].includes(ls[0])) aMerged.delete(id);
		}
	}
	const A = finalize(rankedIds(aMerged), limit);

	// B: grupo 1 = A; grupo 2 = empresas con evidencia de experiencia (literal, vectorial o texto solo por experiencias) fuera del grupo 1.
	const g1 = new Set(A.map((r) => r.id));
	const bLists: Partial<Record<EvidenceList, Evidence[]>>[] = [];
	for (const p of phrases) {
		const full = await fullTextFrom('empresa_search_documents', p);
		const noexp = new Set((await fullTextFrom('esd_noexp', p)).map((r) => r.empresa_id));
		bLists.push({ lexical_experiencia: await lexicalExperienciaRaw(p), fulltext: full.filter((r) => !noexp.has(r.empresa_id)) });
	}
	const bFused = bLists.map((l) => fuse(l));
	const bVec = vectorMap(lv, ['experiencia']);
	const g2all = rankedIds(mergePhraseEvidence([...bFused.map((f) => f.result), bVec.result]));
	const B2 = finalize(g2all.filter((r) => !g1.has(Number(r.empresa_id))), 10);
	const bothExp = A.filter((r) => g2all.some((x) => Number(x.empresa_id) === r.id)).map((r) => r.id);
	const tB = performance.now() - t0;

	const liveOrder = lv.candidates.filter((x) => active.has(Number(x.empresa_id))).sort((a, b) => a.rank - b.rank).slice(0, limit).map((x) => Number(x.empresa_id));
	const actOrder = actual.map((r) => r.id);
	out.push({
		id: c.id, query: c.query, phrases, source: c.source, golden: c.golden ?? null, detected_intent: lv.detected_intent,
		limit, phrase_count: phrases.length, unique_phrase_count: uniq.length,
		live_order: liveOrder, live_ms: lv.total_ms,
		fidelity: { same_set: liveOrder.length === actOrder.length && liveOrder.every((x) => actOrder.includes(x)), same_top5: JSON.stringify(liveOrder.slice(0, 5)) === JSON.stringify(actOrder.slice(0, 5)), same_order: JSON.stringify(liveOrder) === JSON.stringify(actOrder) },
		actual, dedup, A, B: { group1: A, group2: B2, group1_with_experience: bothExp },
		contributions: Object.fromEntries([...actContrib.entries()].filter(([id]) => actOrder.slice(0, 10).includes(id)).map(([id, k]) => [id, Object.fromEntries(Object.entries(k).map(([l, v]) => [l, Math.round((v as number) * 10000) / 10000]))])),
		a_contributions: Object.fromEntries([...aContrib.entries()].filter(([id]) => A.slice(0, 10).some((r) => r.id === id)).map(([id, k]) => [id, Object.fromEntries(Object.entries(k).map(([l, v]) => [l, Math.round((v as number) * 10000) / 10000]))])),
		timings_ms: { actual_clone: Math.round(tAct), A_clone: Math.round(tA), B_clone_total: Math.round(tB) },
	});
	process.stderr.write(`${c.id} ${c.query} live=${liveOrder.length} act=${actOrder.length} same_order=${out.at(-1).fidelity.same_order}\n`);
}
fs.writeFileSync(process.argv[4], JSON.stringify(out, null, 1));
await sql.end({ timeout: 2 });
