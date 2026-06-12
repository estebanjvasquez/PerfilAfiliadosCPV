<?php

use App\Models\User;
use App\Models\Empresa;
use App\Http\Livewire\Form;
use App\Exports\UsersExport;
use App\Exports\JoinViewExport;
use App\Filament\Pages\JoinViews;
use App\Filament\Pages\GenCatalog;
use App\Filament\Pages\JoinedViews;
use Illuminate\Support\Facades\Auth;

//ADDED.....
use App\Filament\Pages\UsrGenCatalog;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PdfController;
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

\Illuminate\Support\Facades\Route::get('form', Form::class);
Route::get('pdf/{empresa}', PdfController::class)->name('pdf');

Route::get('xls/{empresa}', function () {
    return Excel::download(new JoinViewExport, 'join-views.xlsx');
})->name('xls');


Route::get('/empresa', \App\Http\Livewire\Empresa::class);
/* Route::get('/gen-catalog/exportxls', [GenCatalog::class, 'exportAllCatalogXls'])->name('gen-catalog.exportxls');
Route::get('/gen-catalog/exportpdf', [GenCatalog::class, 'exportAllCatalogPdf'])->name('gen-catalog.exportpdf'); */

/* Route::get('/dashboard', function () {
    $user = Auth::User();
    return view('dashboard', compact('user'));
}); */
