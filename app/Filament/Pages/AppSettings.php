<?php

namespace App\Filament\Pages;

use App\Models\AppSetting as AppSettingModel;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\Rule;

class AppSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.app-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('Admin') ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('Application settings');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public function getTitle(): string
    {
        return __('Application settings');
    }

    public function mount(): void
    {
        $this->form->fill([
            'locale' => AppSettingModel::currentLocale(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('locale')
                    ->label(__('Interface language'))
                    ->options([
                        'uk' => __('Ukrainian'),
                        'en' => __('English'),
                    ])
                    ->rules([Rule::in(AppSettingModel::SUPPORTED_LOCALES)])
                    ->required()
                    ->native(false),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->hasRole('Admin'), 403);

        $data = $this->form->getState();
        AppSettingModel::instance()->update([
            'locale' => $data['locale'],
        ]);
        app()->setLocale($data['locale']);

        Notification::make()
            ->title(__('Settings saved'))
            ->success()
            ->send();
    }
}
