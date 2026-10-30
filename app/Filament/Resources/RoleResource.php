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
 * Permission matrix and role management, available ONLY to Admin (spec 2.2).
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('Roles and permissions');
    }

    public static function getModelLabel(): string
    {
        return __('Role');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Roles and permissions');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('People and access');
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->hasRole('Admin') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label(__('Role name'))
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),

            Forms\Components\Section::make(__('Permission matrix'))
                ->description(__('Configure the permissions assigned to this role'))
                ->schema([
                    Forms\Components\CheckboxList::make('permissions')
                        ->label(__('Permissions'))
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
                Tables\Columns\TextColumn::make('name')->label(__('Role'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('permissions_count')
                    ->label(__('Number of permissions'))
                    ->counts('permissions'),
                Tables\Columns\TextColumn::make('users_count')
                    ->label(__('Number of users'))
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
