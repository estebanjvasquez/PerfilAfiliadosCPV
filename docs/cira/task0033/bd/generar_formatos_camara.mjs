// TASK-0033 E5: genera los formatos de respuesta para la Cámara a partir de la sonda de solo lectura
// (sonda_decisiones_camara.php). Versión pública (ids) en docs/preuat/formatos/ y versión con nombres SOLO en
// docs/uat/privado/2026-11-17_preuat/ (excluida de git). CSV con «;» y BOM UTF-8 para Excel en español.
// Uso: node generar_formatos_camara.mjs <publico.json> <privado.json>
import fs from 'node:fs';

const [pubPath, privPath] = process.argv.slice(2);
const pub = JSON.parse(fs.readFileSync(pubPath, 'utf8'));
const priv = privPath && fs.existsSync(privPath) ? JSON.parse(fs.readFileSync(privPath, 'utf8')) : null;
const outPub = 'docs/preuat/formatos';
const outPriv = 'docs/uat/privado/2026-11-17_preuat';
fs.mkdirSync(outPub, { recursive: true });
if (priv) fs.mkdirSync(outPriv, { recursive: true });
const sector = Object.fromEntries(pub.sectores.map((s) => [String(s.id), s.name]));
const sectores = (lista) => (lista ? String(lista).split(',').map((id) => sector[id] ?? id).join(' | ') : '');
const esc = (v) => {
	const s = v == null ? '' : String(v);
	return /[;"\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
};
const write = (path, header, rows) => fs.writeFileSync(path, '﻿' + [header, ...rows].map((r) => r.map(esc).join(';')).join('\r\n') + '\r\n');
const nombre = (lista, id) => lista?.find((x) => String(x.id ?? x.empresa_id) === String(id))?.nombre ?? '';

// F1: empresas sin sector principal.
const h1 = ['empresa_id', 'sector_secundario_actual', 'sectores_que_aparecen_por_sus_servicios', 'tiene_solo_servicios_X', 'RESPUESTA_sector_principal', 'RESPUESTA_sector_secundario', 'RESPUESTA_observaciones'];
const r1 = pub.empresas_sin_sector_principal.map((e) => [e.id, sector[String(e.sector_secundario_id)] ?? '', sectores(e.sectores_por_servicio), Number(e.servicios_no_x) === 0 ? 'sí' : 'no', '', '', '']);
write(`${outPub}/E5_F1_empresas_sin_sector_principal.csv`, h1, r1);
if (priv) write(`${outPriv}/E5_F1_empresas_sin_sector_principal_CON_NOMBRES.csv`, ['empresa_id', 'nombre', ...h1.slice(1)], pub.empresas_sin_sector_principal.map((e, i) => [e.id, nombre(priv.empresas_sin_sector_principal, e.id), ...r1[i].slice(1)]));

// F2: vínculos con el servicio «X».
const h2 = ['empresa_id', 'sector_del_servicio_X', 'prioridad', 'motivo_de_la_prioridad', 'RESPUESTA_servicio_real_en_ese_sector', 'RESPUESTA_quitar_la_empresa_de_ese_sector (sí/no)', 'RESPUESTA_observaciones'];
const motivo = (v) => (v.prioridad === 'alta' ? 'X es su única pertenencia a ese sector y no es su sector declarado: perdería el sector si se retira X' : v.prioridad === 'media' ? 'X es su única pertenencia, pero el sector ya está declarado como principal o secundario' : 'tiene otro servicio en ese sector');
const orden = { alta: 0, media: 1, baja: 2 };
const xs = [...pub.vinculos_x].sort((a, b) => orden[a.prioridad] - orden[b.prioridad] || a.empresa_id - b.empresa_id || a.sector_id - b.sector_id);
const r2 = xs.map((v) => [v.empresa_id, sector[String(v.sector_id)] ?? v.sector_id, v.prioridad, motivo(v), '', '', '']);
write(`${outPub}/E5_F2_vinculos_servicio_X.csv`, h2, r2);
if (priv) write(`${outPriv}/E5_F2_vinculos_servicio_X_CON_NOMBRES.csv`, ['empresa_id', 'nombre', ...h2.slice(1)], xs.map((v, i) => [v.empresa_id, priv.vinculos_x.find((p) => p.empresa_id === v.empresa_id)?.nombre ?? '', ...r2[i].slice(1)]));

// F5: solicitudes de categoría.
const h5 = ['solicitud_id', 'empresa_id', 'fecha', 'motivo', 'estado', 'aviso_por_correo', 'RESPUESTA_decision (crear categoría / usar existente / rechazar)', 'RESPUESTA_categoria_o_codigo', 'RESPUESTA_observaciones'];
const r5 = pub.solicitudes_categoria.map((s) => [s.id, s.empresa_id, String(s.submitted_at).slice(0, 10), s.justificacion, s.request_status, s.delivery_status, '', '', '']);
write(`${outPub}/E5_F5_solicitudes_de_categoria.csv`, h5, r5);
if (priv) write(`${outPriv}/E5_F5_solicitudes_de_categoria_CON_TEXTO.csv`, ['solicitud_id', 'necesidad', 'terminos_probados', ...h5.slice(1)], pub.solicitudes_categoria.map((s, i) => [s.id, priv.solicitudes_categoria.find((p) => p.id === s.id)?.necesidad ?? '', priv.solicitudes_categoria.find((p) => p.id === s.id)?.terminos_probados ?? '', ...r5[i].slice(1)]));

const cuenta = (f) => pub.vinculos_x.filter(f).length;
const empresas = (f) => new Set(pub.vinculos_x.filter(f).map((v) => v.empresa_id)).size;
const resumen = {
	empresas_sin_sector_principal: r1.length,
	de_ellas_con_solo_servicios_X: r1.filter((r) => r[3] === 'sí').length,
	de_ellas_con_sector_secundario: pub.empresas_sin_sector_principal.filter((e) => e.sector_secundario_id).length,
	vinculos_X: pub.vinculos_x.length,
	empresas_con_X: empresas(() => true),
	prioridad_alta: { vinculos: cuenta((v) => v.prioridad === 'alta'), empresas: empresas((v) => v.prioridad === 'alta') },
	prioridad_media: { vinculos: cuenta((v) => v.prioridad === 'media'), empresas: empresas((v) => v.prioridad === 'media') },
	prioridad_baja: { vinculos: cuenta((v) => v.prioridad === 'baja'), empresas: empresas((v) => v.prioridad === 'baja') },
	X_por_sector: Object.fromEntries(pub.sectores.map((s) => [s.name, cuenta((v) => v.sector_id === s.id)])),
	alta_por_sector: Object.fromEntries(pub.sectores.map((s) => [s.name, cuenta((v) => v.sector_id === s.id && v.prioridad === 'alta')])),
	solicitudes: r5.length,
};
fs.writeFileSync(`${outPub}/E5_resumen_cifras.json`, JSON.stringify(resumen, null, 1));
console.log(JSON.stringify(resumen, null, 1));
