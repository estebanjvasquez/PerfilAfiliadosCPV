<?php

namespace App\Http\Livewire;

use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Livewire\Component;

class Form extends Component implements HasForms
{
    use InteractsWithForms;

    public $rif;
    public $name;
    public $phone;
    public $website;
    public $address;
    public $linkedin_profile;
    public $twitter_profile;
    public $instagram_profile;
    public $facebook_profile;
    public $youtube_profile;

    public function mount()
    {
        $this->form->fill();
    }

    protected function getFormSchema(): array
    {
        return [
            Forms\Components\Wizard::make()
                ->schema([
                    Forms\Components\Wizard\Step::make('Datos 1')
                        ->schema([
                            Forms\Components\TextInput::make('rif')->required(),
                            Forms\Components\TextInput::make('name')->required(),
                            Forms\Components\TextInput::make('phone'),
                        ]),
                    Forms\Components\Wizard\Step::make('Datos 2')
                        ->schema([
                            Forms\Components\TextInput::make('website')->url(),
                            Forms\Components\TextInput::make('address'),
                        ]),
                    Forms\Components\Wizard\Step::make('Datos 3')
                        ->schema([
                            Forms\Components\TextInput::make('linkedin_profile'),
                            Forms\Components\TextInput::make('twitter_profile'),
                            Forms\Components\TextInput::make('instagram_profile'),
                            Forms\Components\TextInput::make('facebook_profile'),
                            Forms\Components\TextInput::make('youtube_profile'),
                        ]),

                ])
                ->columns(['sm' => 2,])
                ->columns(['sm' => 2,]),
        ];
    }

    public function submit()
    {
        dd($this->form->getState());
    }

    public function render()
    {
        return view('livewire.form');
    }
}
