<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DutyLimitResource\Pages;
use App\Models\DutyLimit;
use App\Models\Period;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DutyLimitResource extends Resource
{
    protected static ?string $model = DutyLimit::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return __('Duty limits');
    }

    public static function getModelLabel(): string
    {
        return __('Duty limit');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Duty limits');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Scheduling rules');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('General'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label(__('Name'))
                        ->required()
                        ->maxLength(255),
                    Forms\Components\Toggle::make('is_active')
                        ->label(__('Active'))
                        ->default(true)
                        ->inline(false),
                ]),

            Forms\Components\Section::make(__('Limit'))
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('period_id')
                        ->label(__('Period'))
                        ->relationship('period', 'name')
                        ->getOptionLabelFromRecordUsing(fn (Period $record): string => $record->displayName())
                        ->preload()
                        ->required()
                        ->createOptionForm(PeriodResource::formSchema()),
                    Forms\Components\TextInput::make('max_days')
                        ->label(__('Maximum duty days per employee'))
                        ->helperText(__('Counted separately for each employee within each period'))
                        ->integer()
                        ->minValue(0)
                        ->required(),
                    Forms\Components\Radio::make('severity')
                        ->label(__('When the limit is exceeded'))
                        ->options([
                            DutyLimit::SEVERITY_FORBIDDEN => __('Forbid the assignment'),
                            DutyLimit::SEVERITY_WARNING => __('Allow with a warning ⚠️'),
                        ])
                        ->default(DutyLimit::SEVERITY_FORBIDDEN)
                        ->required()
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make(__('Scope'))
                ->schema([
                    Forms\Components\Select::make('groups')
                        ->label(__('Employee groups'))
                        ->relationship('groups', 'name')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->helperText(__('If no groups are selected, the limit applies to all employees.'))
                        ->createOptionForm(EmployeeGroupResource::formSchema()),
                    Forms\Components\Select::make('eventTypes')
                        ->label(__('Event types'))
                        ->relationship('eventTypes', 'name')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->helperText(__('If no event types are selected, all working event types are counted.')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('period.name')
                    ->label(__('Period'))
                    ->state(fn (DutyLimit $record): string => $record->period->displayName()),
                Tables\Columns\TextColumn::make('max_days')->label(__('Max. days')),
                Tables\Columns\TextColumn::make('severity')
                    ->label(__('When exceeded'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === DutyLimit::SEVERITY_FORBIDDEN ? __('Forbid') : __('Warn'))
                    ->color(fn (string $state): string => $state === DutyLimit::SEVERITY_FORBIDDEN ? 'danger' : 'warning'),
                Tables\Columns\TextColumn::make('groups.name')
                    ->label(__('Employee groups'))
                    ->badge()
                    ->placeholder(__('All employees')),
                Tables\Columns\TextColumn::make('eventTypes.name')
                    ->label(__('Event types'))
                    ->badge()
                    ->placeholder(__('All working event types')),
                Tables\Columns\IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label(__('Active status')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with(['period', 'groups', 'eventTypes']));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDutyLimits::route('/'),
            'create' => Pages\CreateDutyLimit::route('/create'),
            'edit' => Pages\EditDutyLimit::route('/{record}/edit'),
        ];
    }
}
