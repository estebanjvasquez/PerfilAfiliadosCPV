<?php

namespace App\Http\Controllers;
//namespace App\Filament\Pages;

use App\Models\Empresa;
use Illuminate\Http\Request;
use App\Models\JoinViewsModel;
use Barryvdh\DomPDF\Facade\Pdf;

class PdfController extends Controller
{
    public function __invoke(Empresa $empresa)
    {
        return Pdf::loadView('pdf', ['record' => $empresa])
            ->stream($empresa->number . '.pdf');
    }
}
