<?php
// Read-only: section list, descriptions in the selector source and in the rendered manual. No DB, no write.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$s = App\Support\CompanyDossier::SECTIONS; $d = App\Support\CompanyDossier::SECTION_DESCRIPTIONS;
$html = app(App\Support\UserManual\UserManualRenderer::class)->render()['html'];
$start = strpos($html, 'id="descargar-perfil"'); $sec = substr($html, $start, (strpos($html, '<h3 ', $start + 1) ?: strlen($html)) - $start);
$missing = array_keys(array_filter($s, fn ($n, $k) => ! str_contains($sec, e($n)) || ! str_contains($sec, e($d[$k])), ARRAY_FILTER_USE_BOTH));
$res = (string) file_get_contents(app_path('Filament/Resources/EmpresaResource.php'));
echo json_encode([
  'sections' => count($s), 'keys' => array_keys($s),
  'descriptions_per_section' => array_keys($d) === array_keys($s),
  'selector_uses_descriptions' => str_contains($res, '->descriptions(CompanyDossier::SECTION_DESCRIPTIONS)'),
  'manual_11_secciones' => str_contains($sec, '11 secciones'),
  'manual_missing_option_or_description' => $missing,
  'only_generales' => App\Support\CompanyDossier::sectionsFrom('generales'),
  'only_operacion' => App\Support\CompanyDossier::sectionsFrom('operacion'),
  'blade_operacion_own_section' => str_contains((string) file_get_contents(resource_path('views/pdf.blade.php')), "@if (\$want('operacion'))"),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
