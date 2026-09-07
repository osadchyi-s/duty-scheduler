<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EventTypeResource\Pages;
use App\Models\EventType;
use App\Models\User;
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

    protected static ?string $navigationLabel = 'Типи подій';

    protected static ?string $modelLabel = 'Тип події';

    protected static ?string $pluralModelLabel = 'Типи подій';

    protected static ?string $navigationGroup = 'Налаштування';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Основне')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Назва')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('code')
                        ->label('Системний код')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(100),
                    Forms\Components\ColorPicker::make('color_code')
                        ->label('Колір у матриці')
                        ->required(),
                    Forms\Components\Toggle::make('is_active')
                        ->label('Активний')
                        ->default(true),
                ]),

            Forms\Components\Section::make('Ексклюзивність та місткість')
                ->columns(3)
                ->schema([
                    Forms\Components\Toggle::make('is_blocking')
                        ->label('Блокуючий тип (is_blocking)')
                        ->helperText('Якщо увімкнено — блокує будь-які інші події співробітника')
                        ->reactive(),
                    Forms\Components\TextInput::make('min_capacity')
                        ->label('Мінімальна к-сть людей')
                        ->numeric()->default(0)->minValue(0),
                    Forms\Components\TextInput::make('max_capacity')
                        ->label('Максимальна к-сть людей')
                        ->numeric()->minValue(0)
                        ->helperText('Порожньо = без обмежень'),
                ]),

            Forms\Components\Section::make('Правила відпочинку')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('min_rest_hours')
                        ->label('Обов’язковий мін. відпочинок (год)')
                        ->numeric()->default(0)->minValue(0)
                        ->helperText('Hard constraint — призначення нижче цього порогу заборонено'),
                    Forms\Components\TextInput::make('preferred_rest_hours')
                        ->label('Бажаний відпочинок (год)')
                        ->numeric()->default(0)->minValue(0)
                        ->helperText('Soft constraint — нижче цього видається попередження ⚠️'),
                ]),

            Forms\Components\Section::make('Автогенерація графіка')
                ->description('Використовується сторінкою «Автогенерація графіка» для автоматичної розстановки')
                ->columns(3)
                ->schema([
                    Forms\Components\Toggle::make('is_schedulable')
                        ->label('Брати участь в автогенерації')
                        ->helperText('Типово вмикається автоматично для типів з min_capacity > 0')
                        ->default(false),
                    Forms\Components\TimePicker::make('default_start_time')
                        ->label('Типовий час початку')
                        ->seconds(false),
                    Forms\Components\TextInput::make('default_duration_hours')
                        ->label('Тривалість зміни (год)')
                        ->numeric()->minValue(1)->maxValue(72),
                ]),

            Forms\Components\Section::make('Дозволи співробітників')
                ->description('Які працівники мають доступ до цього типу події. Це враховується в автогенерації і при перевірці в матриці.')
                ->schema([
                    Forms\Components\CheckboxList::make('allowedUsers')
                        ->label('Співробітники з доступом')
                        ->relationship('allowedUsers', 'name')
                        ->options(fn () => User::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                        ->columns(2),
                ]),

            Forms\Components\Section::make('Облік часу')
                ->schema([
                    Forms\Components\Toggle::make('is_work_time')
                        ->label('Враховується в баланс робочих годин')
                        ->default(true),
                    Forms\Components\TextInput::make('sort_order')
                        ->label('Порядок сортування')
                        ->numeric()->default(0),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ColorColumn::make('color_code')->label('Колір'),
                Tables\Columns\TextColumn::make('name')->label('Назва')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('code')->label('Код')->badge(),
                Tables\Columns\IconColumn::make('is_blocking')->label('Блокуючий')->boolean(),
                Tables\Columns\TextColumn::make('min_capacity')->label('Min люд.'),
                Tables\Columns\TextColumn::make('max_capacity')->label('Max люд.')->placeholder('∞'),
                Tables\Columns\TextColumn::make('min_rest_hours')->label('Мін. відпочинок, год'),
                Tables\Columns\TextColumn::make('preferred_rest_hours')->label('Бажаний відпочинок, год'),
                Tables\Columns\IconColumn::make('is_schedulable')->label('Автогенерація')->boolean(),
                Tables\Columns\IconColumn::make('is_work_time')->label('Роб. час')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->label('Активний')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Активність'),
                Tables\Filters\TernaryFilter::make('is_blocking')->label('Блокуючий'),
            ])
            ->actions([
                Tables\Actions\Action::make('duplicate')
                    ->label('Копіювати')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->action(function (EventType $record) {
                        $baseCode = $record->code ?? 'event_type';
                        $newCode = $baseCode.'_copy';
                        if (EventType::where('code', $newCode)->exists()) {
                            $newCode = $baseCode.'_copy_'.Str::slug(now()->format('Ymd_His'));
                        }

                        $clone = $record->replicate();
                        $clone->name = $record->name.' (Копія)';
                        $clone->code = $newCode;
                        $clone->save();

                        $clone->allowedUsers()->sync($record->allowedUsers()->pluck('users.id')->all());

                        Notification::make()
                            ->title('Тип події скопійовано')
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
