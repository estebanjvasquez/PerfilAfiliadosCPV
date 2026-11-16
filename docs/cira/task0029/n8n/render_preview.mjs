// TASK-0029: vista previa local de `Format Cards2` (sin n8n). Ejecuta el código del nodo con respuestas reales
// de search_empresas y lo inserta en una página con el CSS de CIRA Test, igual que addBot() (cada \n → <br>).
// Uso: node render_preview.mjs <flujo.json> <salida.html> <caso1.json> [caso2.json ...]
//   cada caso: { "titulo": "...", "summary": "...", "intent": "SERVICE", "rows": [...] }
import fs from 'node:fs';

const [flowPath, outPath, ...casePaths] = process.argv.slice(2);
const flow = JSON.parse(fs.readFileSync(flowPath, 'utf8'));
const code = flow.nodes.find((n) => n.name === 'Format Cards2').parameters.jsCode;
const page = fs.readFileSync(new URL('../../../../public/cira-test/index.html', import.meta.url), 'utf8');
const style = (page.match(/<style>[\s\S]*?<\/style>/) ?? [''])[0];

function run(c) {
	const $json = { rows: c.rows, search_log_id: c.logId ?? 999999 };
	const $ = (name) => ({ first: () => ({ json: name === 'Parse Intent JSON3' ? { humanSummary: c.summary, queryIntent: c.intent ?? 'SERVICE' } : {} }) });
	const fn = new Function('$json', '$', code);
	return fn($json, $)[0].json.output;
}

const blocks = casePaths.map((p) => {
	const c = JSON.parse(fs.readFileSync(p, 'utf8'));
	const html = run(c);
	// El formato agrupado no debe traer saltos de línea; el anterior (sin `grupo`) ya los traía y no se tocó.
	if (/\n/.test(html) && c.rows.some((r) => r.grupo)) throw new Error('la salida agrupada trae saltos de línea: ' + p);
	return `<h2 style="font:600 14px sans-serif;margin:24px 8px 8px">${c.titulo}</h2><div class="row bot"><img class="avatar" src="" alt=""><div class="bubble">${html.replace(/\n/g, '<br>')}</div></div>`;
});

fs.writeFileSync(
	outPath,
	`<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Vista previa TASK-0029</title>${style}</head>` +
		`<body><div class="msgs" style="padding:8px">${blocks.join('')}</div><script>window.sendPrompt=function(t){document.title='prompt: '+t};window.ciraVote=function(){};</script></body></html>`
);
console.log('ok', outPath, blocks.length);
