// TASK-0031: envía las entradas al webhook de una copia aislada, en serie y con una sesión nueva por consulta.
// Uso: N8N_BASE_URL=... node run_variant.mjs <corpus_inputs.json> <webhook_path> <variante> <repeticiones_uat> <salida.json>
// El sessionId codifica variante, caso y repetición para emparejar después con la ejecución de n8n.
import fs from 'node:fs';

const [inPath, hookPath, variant, reps = '3', outPath] = process.argv.slice(2);
const { corpus, uat } = JSON.parse(fs.readFileSync(inPath, 'utf8'));
const url = process.env.N8N_BASE_URL.replace(/\/$/, '') + '/webhook/' + hookPath;
const jobs = [];
for (let r = 1; r <= Number(reps); r++) for (const u of uat) jobs.push({ id: u.id, input: u.input, rep: r });
for (const c of corpus) jobs.push({ id: c.id, input: c.input, rep: 1 });

const out = [];
for (const j of jobs) {
	const sessionId = `t31-${variant}-${j.id}-r${j.rep}-${Date.now()}`;
	const t0 = Date.now();
	let status = 0, type = null, len = 0, err = null;
	try {
		const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ chatInput: j.input, sessionId }) });
		status = r.status;
		const body = await r.json().catch(() => ({}));
		type = body.type ?? null;
		len = String(body.output ?? '').length;
	} catch (e) {
		err = String(e.message ?? e);
	}
	out.push({ ...j, variant, sessionId, client_ms: Date.now() - t0, status, type, output_len: len, error: err, at: new Date(t0).toISOString() });
	process.stderr.write(`${variant} ${j.id} r${j.rep} ${status} ${Date.now() - t0}ms ${type}\n`);
}
fs.writeFileSync(outPath, JSON.stringify(out, null, 1));
