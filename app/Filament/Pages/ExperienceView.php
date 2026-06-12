<?php

namespace App\Filament\Pages;

use App\Exports\ExperienceExport;
use App\Models\ExperienceViewModel;
use Filament\Pages\Page;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Actions\Action;
use Maatwebsite\Excel\Facades\Excel;

class ExperienceView extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Experiencia Relevante';

    protected static ?string $title = 'Experiencia Relevante';

    protected static ?string $slug = 'experience-view';

    protected static ?string $navigationGroup = 'Reportes';

    protected static string $view = 'filament.pages.experience-view';

    protected static ?int $navigationSort = 7;

    use HasPageShield;

    public $experiences;

    protected function getActions(): array
    {
        return [
            Action::make('Excel')->action('exportAllExperienceXls'),
            Action::make('Pdf')->action('exportAllExperiencePdf'),
        ];
    }

    public function exportAllExperienceXls()
    {
        return Excel::download(new ExperienceExport, 'experiencia.xlsx');
    }

    public function exportAllExperiencePdf()
    {

        //return Excel::download(new ExperienceExport, 'experiencia.pdf');
        $export = new ExperienceExport();
        return Excel::download($export, 'experience.pdf', \Maatwebsite\Excel\Excel::MPDF);
    }
}
