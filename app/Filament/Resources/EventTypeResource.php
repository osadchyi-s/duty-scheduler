<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EventTypeResource\Pages;
use App\Models\EventType;
use App\Models\User;
use App\Support\TimeUnit;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class EventTypeResource extends Resource
{
    protected static ?string $model = EventType::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return __('Event types');
    }

    public static function getModelLabel(): string
    {
        return __('Event type');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Event types');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
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
                    Forms\Components\TextInput::make('code')
                        ->label(__('System code'))
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(100),
                    Forms\Components\ColorPicker::make('color_code')
                        ->label(__('Matrix color'))
                        ->required(),
                    Forms\Components\Toggle::make('is_active')
                        ->label(__('Active'))
                        ->default(true),
                ]),

            Forms\Components\Section::make(__('Exclusivity and capacity'))
                ->columns(3)
                ->schema([
                    Forms\Components\Toggle::make('is_blocking')
                        ->label(__('Blocking type (is_blocking)'))
                        ->helperText(__('When enabled, this blocks any other event for the employee'))
                        ->reactive(),
                    Forms\Components\TextInput::make('min_capacity')
                        ->label(__('Minimum number of people'))
                        ->numeric()->default(0)->minValue(0),
                    Forms\Components\TextInput::make('max_capacity')
                        ->label(__('Maximum number of people'))
                        ->numeric()->minValue(0)
                        ->helperText(__('Leave blank for no limit')),
                ]),

            Forms\Components\Section::make(__('Rest period rules'))
                ->columns(2)
                ->schema([
                    self::hoursInput('min_rest_hours')
                        ->label(TimeUnit::usesDays() ? __('Required minimum rest (days)') : __('Required minimum rest (hours)'))
                        ->default(0)->minValue(0)
                        ->helperText(__('Hard constraint — assignments below this threshold are forbidden')),
                    self::hoursInput('preferred_rest_hours')
                        ->label(TimeUnit::usesDays() ? __('Preferred rest (days)') : __('Preferred rest (hours)'))
                        ->default(0)->minValue(0)
                        ->helperText(__('Soft constraint — assignments below this threshold trigger a warning ⚠️')),
                ]),

            Forms\Components\Section::make(__('Schedule auto-generation'))
                ->description(__('Used by the “Schedule auto-generation” page for automatic assignment'))
                ->columns(TimeUnit::usesDays() ? 2 : 3)
                ->schema([
                    Forms\Components\Toggle::make('is_schedulable')
                        ->label(__('Include in auto-generation'))
                        ->helperText(__('Enabled by default for event types with a minimum capacity greater than zero'))
                        ->default(false),
                    // In "days" mode every event starts at 00:00, so the start time is not editable.
                    Forms\Components\TimePicker::make('default_start_time')
                        ->label(__('Default start time'))
                        ->seconds(false)
                        ->visible(! TimeUnit::usesDays()),
                    self::hoursInput('default_duration_hours')
                        ->label(TimeUnit::usesDays() ? __('Shift duration (days)') : __('Shift duration (hours)'))
                        ->minValue(1)->maxValue(TimeUnit::toDisplay(72)),
                ]),

            Forms\Components\Section::make(__('Employee permissions'))
                ->description(__('Employees allowed to be assigned this event type. Applied during auto-generation and matrix validation.'))
                ->schema([
                    Forms\Components\CheckboxList::make('allowedUsers')
                        ->label(__('Employees with access'))
                        ->relationship('allowedUsers', 'name')
                        ->options(fn () => User::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                        ->columns(2),
                ]),

            Forms\Components\Section::make(__('Time tracking'))
                ->schema([
                    Forms\Components\Toggle::make('is_work_time')
                        ->label(__('Included in the working-hours balance'))
                        ->default(true),
                    Forms\Components\TextInput::make('sort_order')
                        ->label(__('Sort order'))
                        ->numeric()->default(0),
                ]),
        ]);
    }

    /**
     * Numeric input for a column stored in hours but edited in the interface unit.
     */
    protected static function hoursInput(string $name): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make($name)
            ->integer()
            ->formatStateUsing(fn ($state) => TimeUnit::toDisplay($state))
            ->dehydrateStateUsing(fn ($state) => TimeUnit::toHours($state));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ColorColumn::make('color_code')->label(__('Color')),
                Tables\Columns\TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('code')->label(__('Code'))->badge(),
                Tables\Columns\IconColumn::make('is_blocking')->label(__('Blocking'))->boolean(),
                Tables\Columns\TextColumn::make('min_capacity')->label(__('Min. people')),
                Tables\Columns\TextColumn::make('max_capacity')->label(__('Max. people'))->placeholder('∞'),
                Tables\Columns\TextColumn::make('min_rest_hours')
                    ->label(TimeUnit::usesDays() ? __('Minimum rest, days') : __('Minimum rest, hours'))
                    ->formatStateUsing(fn ($state) => TimeUnit::toDisplay($state)),
                Tables\Columns\TextColumn::make('preferred_rest_hours')
                    ->label(TimeUnit::usesDays() ? __('Preferred rest, days') : __('Preferred rest, hours'))
                    ->formatStateUsing(fn ($state) => TimeUnit::toDisplay($state)),
                Tables\Columns\IconColumn::make('is_schedulable')->label(__('Auto-generation'))->boolean(),
                Tables\Columns\IconColumn::make('is_work_time')->label(__('Working time'))->boolean(),
                Tables\Columns\IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label(__('Active status')),
                Tables\Filters\TernaryFilter::make('is_blocking')->label(__('Blocking')),
            ])
            ->actions([
                Tables\Actions\Action::make('duplicate')
                    ->label(__('Duplicate'))
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->action(function (EventType $record) {
                        $baseCode = $record->code ?? 'event_type';
                        $newCode = $baseCode.'_copy';
                        if (EventType::where('code', $newCode)->exists()) {
                            $newCode = $baseCode.'_copy_'.Str::slug(now()->format('Ymd_His'));
                        }

                        $clone = $record->replicate();
                        $clone->name = $record->name.' ('.__('Copy').')';
                        $clone->code = $newCode;
                        $clone->save();

                        $clone->allowedUsers()->sync($record->allowedUsers()->pluck('users.id')->all());

                        Notification::make()
                            ->title(__('Event type copied'))
                            ->success()
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEventTypes::route('/'),
            'create' => Pages\CreateEventType::route('/create'),
            'edit' => Pages\EditEventType::route('/{record}/edit'),
        ];
    }
}
