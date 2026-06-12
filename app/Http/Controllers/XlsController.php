<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;

class XlsController extends Controller
{
    public function __invoke(Empresa $empresa)
    {
        return Pdf::loadView('pdf', ['record' => $empresa])
            ->stream($empresa->number . '.pdf');
    }
}
