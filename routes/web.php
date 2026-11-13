<?php

use App\Models\User;
use App\Models\Empresa;
use App\Http\Livewire\Form;
use App\Exports\UsersExport;
use App\Filament\Pages\JoinViews;
use App\Filament\Pages\GenCatalog;
use App\Filament\Pages\JoinedViews;
use Illuminate\Support\Facades\Auth;

//ADDED.....
use App\Filament\Pages\UsrGenCatalog;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\XlsController;
use App\Http\Controllers\JointableController;
use App\Http\Controllers\JoinedViewsController;
use Maatwebsite\Excel\Facades\Excel;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/


Route::get('/', function () {
    $user = Auth::User();
    return view('campet', compact('user'));
})->name('home');

// CPV-EXPORT-ACCESS-REPAIR-AND-ERROR-PAGES (Issue #2 `6264837159`): las rutas viejas de descarga ya no generan
// nada. Solo redirigen a las del panel (filament.admin.export.*), que exigen sesión, correo verificado y
// acceso a la empresa. Así los enlaces y marcadores viejos siguen funcionando. El parámetro se pasa como texto
// y no se consulta la base aquí: la respuesta no dice si la empresa existe.
Route::get('pdf/{empresa}', fn (string $empresa) => redirect()->route('filament.admin.export.pdf', ['empresa' => $empresa]))->name('pdf');
Route::get('xls/{empresa}', fn (string $empresa) => redirect()->route('filament.admin.export.xls', ['empresa' => $empresa]))->name('xls');

// TASK-0027 (Issue #2 `6852945146`): entry points from CIRA Test (a static page) to the evaluation module. They only
// redirect to the panel pages, which require a session and an active invitation; only well-formed parameters are passed
// on (digits for the search, the CIRA Test session format, one of the 8 test codes) and nothing is looked up here.
Route::get('cira-test/evaluacion', function (\Illuminate\Http\Request $request) {
    $params = array_filter([
        'log' => ctype_digit((string) $request->query('log')) ? (string) $request->query('log') : null,
        'sid' => \App\Services\CiraEvaluation\CiraEvaluationService::isValidSid($request->query('sid')) ? (string) $request->query('sid') : null,
        'test' => in_array($request->query('test'), \App\Services\CiraEvaluation\CiraEvaluationService::testCodes(), true) ? (string) $request->query('test') : null,
    ]);

    return redirect()->to(\App\Filament\Pages\CiraEvaluationFormPage::getUrl($params, panel: 'admin'));
})->name('cira-eval.evaluate');
Route::get('cira-test/evaluacion/mis-pruebas', fn () => redirect()->to(\App\Filament\Pages\CiraEvaluationHomePage::getUrl(panel: 'admin')))->name('cira-eval.home');
Route::get('cira-test/evaluacion/guia', fn () => redirect()->to(\App\Filament\Pages\CiraEvaluationGuidePage::getUrl(panel: 'admin')))->name('cira-eval.guide');


/* Route::get('/gen-catalog/exportxls', [GenCatalog::class, 'exportAllCatalogXls'])->name('gen-catalog.exportxls');
Route::get('/gen-catalog/exportpdf', [GenCatalog::class, 'exportAllCatalogPdf'])->name('gen-catalog.exportpdf'); */

/* Route::get('/dashboard', function () {
    $user = Auth::User();
    return view('dashboard', compact('user'));
}); */
