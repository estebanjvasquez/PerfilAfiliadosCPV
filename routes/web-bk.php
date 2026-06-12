<?php

use App\Filament\Pages\GenCatalog;

use App\Filament\Pages\JoinedViews;
use App\Filament\Pages\JoinViews;
use App\Filament\Pages\UsrGenCatalog;
use App\Http\Controllers\JoinedViewsController;
use App\Http\Controllers\JointableController;

use Illuminate\Support\Facades\Route;
use App\Models\User;
use App\Models\Empresa;
use Illuminate\Support\Facades\Auth;

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
    // $user = User::find(2);
    $user = Auth::User();

    //dd($user);
    //$empresas = $user->empresas()->orderBy('name')->get();
    //dd($user);
    return view('campet', compact('user'));
});
//Route::get('/home', \App\Http\Livewire\Form::class);
//Route::get('/empresa', \App\Http\Livewire\Empresa::class);
//Route::get('/empresas', \Pages\ListEmpresas::class);

Route::get('/empresa', \App\Http\Livewire\Empresa::class);

Route::get('/gen-catalog/exportxls', [GenCatalog::class, 'exportAllCatalogXls'])->name('gen-catalog.exportxls');
Route::get('/gen-catalog/exportpdf', [GenCatalog::class, 'exportAllCatalogPdf'])->name('gen-catalog.exportpdf');

Route::get('join-views', [JoinViews::class, 'index'])->name('join-views');

/* Route::get('/dashboard', function () {
    $user = Auth::User();
    return view('dashboard', compact('user'));
}); */
