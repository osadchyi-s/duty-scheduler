<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PeriodResource\Pages;
use App\Models\Period;
use App\Periods\PeriodTypes;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PeriodResource extends Resource
{
    protected static ?string $model = Period::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar';

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('Periods');
    }

    public static function getModelLabel(): string
    {
        return __('Period');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Periods');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Scheduling rules');
    }

    public static function form(Form $form): Form
    {
        return $form->schema(self::formSchema());
    }

    /**
     * Also used to create a period directly from the duty limit form.
     * Each period type contributes its own settings fields under "params".
     */
    public static function formSchema(): array
    {
        $typeFields = [];
        foreach (PeriodTypes::creatableOptions() as $key => $label) {
            $typeFields[] = Forms\Components\Group::make(PeriodTypes::get($key)->formSchema())
                ->statePath('params')
                ->columns(3)
                ->columnSpanFull()
                ->visible(fn (Forms\Get $get): bool => $get('type') === $key);
        }

        return [
            Forms\Components\TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255),
            Forms\Components\Select::make('type')
                ->label(__('Period type'))
                ->options(PeriodTypes::creatableOptions())
                ->required()
                ->live(),
            ...$typeFields,
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('Name'))
                    ->state(fn (Period $record): string => $record->displayName())
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('Period type'))
                    ->formatStateUsing(fn (string $state): string => PeriodTypes::get($state)->label()),
                Tables\Columns\TextColumn::make('params')
                    ->label(__('Settings'))
                    ->state(fn (Period $record): string => $record->periodType()->describe($record->params ?? [])),
                Tables\Columns\IconColumn::make('is_system')->label(__('Built-in'))->boolean(),
                Tables\Columns\TextColumn::make('duty_limits_count')->label(__('Duty limits'))->counts('dutyLimits'),
            ])
            ->defaultSort('id')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPeriods::route('/'),
            'create' => Pages\CreatePeriod::route('/create'),
            'edit' => Pages\EditPeriod::route('/{record}/edit'),
        ];
    }
}
