<?php

namespace App\Filament\Resources;

use App\Filament\Actions\DeleteAllowedBulkAction;
use App\Filament\Resources\EmployeeGroupResource\Pages;
use App\Models\EmployeeGroup;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class EmployeeGroupResource extends Resource
{
    protected static ?string $model = EmployeeGroup::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('Employee groups');
    }

    public static function getModelLabel(): string
    {
        return __('Employee group');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Employee groups');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('People and access');
    }

    public static function form(Form $form): Form
    {
        return $form->schema(self::formSchema());
    }

    /**
     * Also used to create a group directly from the duty limit form.
     */
    public static function formSchema(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->label(__('Name'))
                ->required()
                ->maxLength(255),
            Forms\Components\Textarea::make('description')
                ->label(__('Description'))
                ->rows(2),
            Forms\Components\CheckboxList::make('users')
                ->label(__('Employees'))
                ->relationship('users', 'name')
                ->options(fn () => User::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->bulkToggleable()
                ->columns(2),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('users_count')->label(__('Employees'))->counts('users'),
                Tables\Columns\TextColumn::make('description')->label(__('Description'))->limit(60),
            ])
            ->defaultSort('name')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    DeleteAllowedBulkAction::make()
                        ->skippedReason(__('Groups used by duty limits cannot be deleted: :count.')),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployeeGroups::route('/'),
            'create' => Pages\CreateEmployeeGroup::route('/create'),
            'edit' => Pages\EditEmployeeGroup::route('/{record}/edit'),
        ];
    }
}
