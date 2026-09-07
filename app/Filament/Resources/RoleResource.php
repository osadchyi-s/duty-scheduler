<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Матриця прав та управління ролями — доступно ВИКЛЮЧНО Admin (п.2.2 ТЗ).
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Ролі та права';

    protected static ?string $modelLabel = 'Роль';

    protected static ?string $pluralModelLabel = 'Ролі та права';

    protected static ?string $navigationGroup = 'Кадри та доступ';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return Auth::user()?->hasRole('Admin') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Назва ролі')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),

            Forms\Components\Section::make('Матриця прав')
                ->description('Довільне перепризначення атомарних прав для цієї ролі')
                ->schema([
                    Forms\Components\CheckboxList::make('permissions')
                        ->label('Права')
                        ->relationship('permissions', 'name')
                        ->options(fn () => Permission::query()->pluck('name', 'id'))
                        ->columns(3)
                        ->searchable(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Роль')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('permissions_count')
                    ->label('К-сть прав')
                    ->counts('permissions'),
                Tables\Columns\TextColumn::make('users_count')
                    ->label('К-сть користувачів')
                    ->counts('users'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Role $record) => ! in_array($record->name, ['Admin', 'Manager', 'User'])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
