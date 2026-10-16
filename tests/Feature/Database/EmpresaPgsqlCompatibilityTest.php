<?php

namespace Tests\Feature\Database;

use App\Models\CompanyCrawlRun;
use App\Models\CompanyPage;
use App\Models\CompanyTermMatch;
use App\Models\Empresa;
use App\Models\EmpresaPgsql;
use App\Models\EmpresaTaxonomyCategory;
use App\Models\TaxonomyCategory;
use App\Models\TaxonomyCategoryRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * CPV-MODEL-COMPATIBILITY-AND-LABELS (Issue #2 `6211997650`, investigación `6190871814`).
 *
 * `EmpresaPgsql` redefinía `taxonomyCategories()` como belongsToMany sin tipo, incompatible con
 * `Empresa::taxonomyCategories(): HasMany`. PHP lo rechaza con un error fatal al CARGAR la clase. Por eso
 * la carga se prueba en un subproceso: un fatal ahí no puede tumbar el proceso de PHPUnit.
 *
 * Los datos son sintéticos y viven en el PostgreSQL desechable del entorno aislado. Se deshacen con el
 * rollback de cada test.
 */
class EmpresaPgsqlCompatibilityTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    /** Carga una clase en un proceso PHP aparte (solo el autoload de Composer, sin arrancar Laravel). */
    private function loadInSubprocess(string $class, ?string $extraFile = null): Process
    {
        $code = 'require '.var_export(base_path('vendor/autoload.php'), true).';'
            .($extraFile ? 'require '.var_export($extraFile, true).';' : '')
            .'echo class_exists('.var_export($class, true).') ? "LOADED" : "MISSING";';
        $process = new Process([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code], base_path());
        $process->setTimeout(60)->run();

        return $process;
    }

    #[Test]
    public function the_pre_fix_override_is_a_class_loading_fatal_reproduced_in_a_subprocess(): void
    {
        // La definición anterior a la reparación, copiada tal cual con otro nombre de clase.
        $file = tempnam(sys_get_temp_dir(), 'empresa_pgsql_prefix_').'.php';
        file_put_contents($file, <<<'PHP'
            <?php
            namespace Tests\PreFix;
            class EmpresaPgsqlPreFix extends \App\Models\Empresa
            {
                protected $connection = 'pgsql';
                protected $table = 'empresas';
                public function taxonomyCategories()
                {
                    return $this->belongsToMany(\App\Models\TaxonomyCategory::class, 'empresa_taxonomy_category', 'empresa_id', 'category_id')
                        ->withPivot(['origen', 'approved_by', 'approved_at'])->withTimestamps();
                }
            }
            PHP);

        try {
            $process = $this->loadInSubprocess('Tests\PreFix\EmpresaPgsqlPreFix', $file);
        } finally {
            @unlink($file);
        }

        $this->assertNotSame(0, $process->getExitCode(), 'La definición previa debía fallar al cargar.');
        $this->assertStringContainsString('must be compatible with App\Models\Empresa::taxonomyCategories(): Illuminate\Database\Eloquent\Relations\HasMany', $process->getErrorOutput().$process->getOutput());
        $this->assertStringNotContainsString('LOADED', $process->getOutput());
    }

    #[Test]
    public function the_repaired_class_loads_in_a_subprocess(): void
    {
        $process = $this->loadInSubprocess(EmpresaPgsql::class);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('LOADED', trim($process->getOutput()));
    }

    #[Test]
    public function it_uses_pgsql_and_empresas_and_keeps_the_parent_link_record_relationship(): void
    {
        $model = new EmpresaPgsql;

        $this->assertSame('pgsql', $model->getConnectionName());
        $this->assertSame('empresas', $model->getTable());
        $this->assertFalse(method_exists(EmpresaPgsql::class, 'taxonomyCategories') && (new \ReflectionMethod(EmpresaPgsql::class, 'taxonomyCategories'))->class === EmpresaPgsql::class, 'No debe redefinir la relación.');

        $relation = $model->taxonomyCategories();
        $this->assertInstanceOf(HasMany::class, $relation);
        $this->assertInstanceOf(EmpresaTaxonomyCategory::class, $relation->getRelated());
        $this->assertSame('pgsql', $relation->getRelated()->getConnectionName());
        $this->assertSame('empresa_id', $relation->getForeignKeyName());
    }

    /** @return array{0: Empresa, 1: User, 2: TaxonomyCategory, 3: TaxonomyCategory} */
    private function fixture(): array
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $empresa = Empresa::create([
            'rif' => 'J'.random_int(10000000, 99999999).'-'.random_int(0, 9),
            'name' => 'zzz_test_empresa_pgsql_'.uniqid('', true),
            'phone' => '0212-5551234',
            'website' => 'https://ejemplo.test',
        ]);
        $grupo = TaxonomyCategory::create(['code' => 'CPV-'.random_int(900, 999), 'parent_id' => null, 'is_active' => true, 'source_version' => 'test']);
        $hojaA = TaxonomyCategory::create(['code' => $grupo->code.'.'.random_int(10, 49), 'parent_id' => $grupo->id, 'is_active' => true, 'source_version' => 'test']);
        $hojaB = TaxonomyCategory::create(['code' => $grupo->code.'.'.random_int(50, 98), 'parent_id' => $grupo->id, 'is_active' => true, 'source_version' => 'test']);

        return [$empresa, $user, $hojaA, $hojaB];
    }

    #[Test]
    public function link_records_and_their_metadata_are_the_same_through_both_classes(): void
    {
        [$empresa, $user, $hojaA, $hojaB] = $this->fixture();
        $empresa->taxonomyCategories()->create(['category_id' => $hojaA->id, 'origen' => EmpresaTaxonomyCategory::ORIGEN_VALIDATED, 'es_principal' => true, 'approved_by' => $user->id, 'approved_at' => now()]);
        $empresa->taxonomyCategories()->create(['category_id' => $hojaB->id, 'origen' => EmpresaTaxonomyCategory::ORIGEN_SELF_DECLARED, 'es_principal' => false]);

        $pg = EmpresaPgsql::findOrFail($empresa->id);
        $this->assertSame($empresa->name, $pg->name);
        $this->assertSame('pgsql', $pg->getConnectionName());

        $links = $pg->taxonomyCategories()->orderBy('category_id')->get();
        $this->assertContainsOnlyInstancesOf(EmpresaTaxonomyCategory::class, $links);
        $this->assertSame(
            Empresa::findOrFail($empresa->id)->taxonomyCategories()->orderBy('category_id')->pluck('id')->all(),
            $links->pluck('id')->all(),
            'Las dos clases deben devolver los mismos registros de vínculo.'
        );

        $principal = $links->firstWhere('category_id', $hojaA->id);
        $this->assertSame(EmpresaTaxonomyCategory::ORIGEN_VALIDATED, $principal->origen);
        $this->assertTrue($principal->es_principal);
        $this->assertSame($user->id, (int) $principal->approved_by);
        $this->assertNotNull($principal->approved_at);
        $this->assertSame($hojaA->id, $principal->category->id, 'Desde el vínculo se llega a la categoría.');

        $declarada = $links->firstWhere('category_id', $hojaB->id);
        $this->assertSame(EmpresaTaxonomyCategory::ORIGEN_SELF_DECLARED, $declarada->origen);
        $this->assertFalse($declarada->es_principal);
        $this->assertNull($declarada->approved_at);
    }

    #[Test]
    public function the_five_inverse_empresa_relations_resolve_to_empresa_pgsql(): void
    {
        [$empresa, $user, $hojaA] = $this->fixture();
        $db = DB::connection('pgsql');
        $now = now();

        $link = $empresa->taxonomyCategories()->create(['category_id' => $hojaA->id, 'origen' => EmpresaTaxonomyCategory::ORIGEN_SELF_DECLARED, 'es_principal' => true]);
        $runId = $db->table('company_crawl_runs')->insertGetId(['empresa_id' => $empresa->id, 'status' => 'completed', 'created_at' => $now, 'updated_at' => $now]);
        $pageId = $db->table('company_pages')->insertGetId(['crawl_run_id' => $runId, 'empresa_id' => $empresa->id, 'url' => 'https://ejemplo.test/servicios', 'fetched_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        $termId = $db->table('taxonomy_terms')->insertGetId(['external_id' => 'zzz-test-'.uniqid(), 'term' => 'bomba zzz', 'language' => 'es', 'canonical_term' => 'bomba zzz', 'term_type' => 'service', 'created_at' => $now, 'updated_at' => $now]);
        $matchId = $db->table('company_term_matches')->insertGetId(['page_id' => $pageId, 'empresa_id' => $empresa->id, 'taxonomy_term_id' => $termId, 'matched_text' => 'bomba zzz', 'canonical_term' => 'bomba zzz', 'match_type' => 'exact', 'evidence_score' => 0.5, 'crawled_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        $requestId = $db->table('taxonomy_category_requests')->insertGetId(['empresa_id' => $empresa->id, 'user_id' => $user->id, 'necesidad' => 'Prueba sintética', 'justificacion' => 'CAPACIDAD_ESPECIALIZADA', 'detalle' => 'Detalle sintético', 'context_snapshot' => '{}', 'submitted_at' => $now, 'created_at' => $now, 'updated_at' => $now]);

        $owners = [
            'EmpresaTaxonomyCategory' => EmpresaTaxonomyCategory::findOrFail($link->id),
            'CompanyCrawlRun' => CompanyCrawlRun::findOrFail($runId),
            'CompanyPage' => CompanyPage::findOrFail($pageId),
            'CompanyTermMatch' => CompanyTermMatch::findOrFail($matchId),
            'TaxonomyCategoryRequest' => TaxonomyCategoryRequest::findOrFail($requestId),
        ];

        foreach ($owners as $name => $owner) {
            $relation = $owner->empresa();
            $this->assertInstanceOf(BelongsTo::class, $relation, $name);
            $this->assertSame('empresa_id', $relation->getForeignKeyName(), $name);

            $resolved = $owner->empresa;
            $this->assertInstanceOf(EmpresaPgsql::class, $resolved, "{$name}->empresa");
            $this->assertSame($empresa->id, $resolved->id, "{$name}->empresa");
            $this->assertSame('pgsql', $resolved->getConnectionName(), $name);

            // Carga ansiosa, como la usaría una tabla de Filament.
            $eager = $owner::query()->with('empresa')->findOrFail($owner->getKey());
            $this->assertSame($empresa->id, $eager->empresa->id, "{$name} with('empresa')");
        }

        // Desde la empresa resuelta por la inversa, los vínculos siguen siendo registros con metadatos.
        $this->assertSame([$link->id], $owners['TaxonomyCategoryRequest']->empresa->taxonomyCategories()->pluck('id')->all());
    }
}
