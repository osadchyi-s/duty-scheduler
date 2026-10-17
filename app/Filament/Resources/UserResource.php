<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers\SynergiesRelationManager;
use App\Models\EventType;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Співробітники';

    protected static ?string $modelLabel = 'Співробітник';

    protected static ?string $pluralModelLabel = 'Співробітники';

    protected static ?string $navigationGroup = 'Кадри та доступ';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Обліковий запис')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')->label('ПІБ')->required()->maxLength(255),
                    Forms\Components\TextInput::make('email')->label('Email')->email()->required()
                        ->unique(ignoreRecord: true)->maxLength(255),
                    Forms\Components\TextInput::make('password')
                        ->label('Пароль')
                        ->password()
                        ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                        ->dehydrated(fn ($state) => filled($state))
                        ->required(fn (string $context) => $context === 'create')
                        ->helperText('Залиште порожнім, щоб не змінювати'),
                    Forms\Components\TextInput::make('position')->label('Посада')->maxLength(255),
                    Forms\Components\Toggle::make('is_active')->label('Активний')->default(true),
                ]),

            Forms\Components\Section::make('Роль доступу')
                ->schema([
                    Forms\Components\Select::make('roles')
                        ->label('Роль')
                        ->relationship('roles', 'name')
                        ->options(function () {
                            // Manager може призначати лише User/Manager, не Admin.
                            $roles = Role::query();
                            if (! Auth::user()?->hasRole('Admin')) {
                                $roles->whereIn('name', ['Manager', 'User']);
                            }

                            return $roles->pluck('name', 'id');
                        })
                        ->multiple()
                        ->preload()
                        ->required(),
                ]),

            Forms\Components\Section::make('Допуски до типів подій (user_event_type_permissions)')
                ->description('Які типи чергувань/відсутностей дозволено призначати цьому співробітнику')
                ->schema([
                    Forms\Components\CheckboxList::make('allowedEventTypes')
                        ->label('Дозволені типи подій')
                        ->relationship('allowedEventTypes', 'name')
                        ->options(fn () => EventType::query()->orderBy('sort_order')->pluck('name', 'id'))
                        ->columns(2),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('ПІБ')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->label('Email')->searchable(),
                Tables\Columns\TextColumn::make('position')->label('Посада'),
                Tables\Columns\TextColumn::make('roles.name')->label('Ролі')->badge(),
                Tables\Columns\IconColumn::make('is_active')->label('Активний')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('roles')->relationship('roles', 'name')->label('Роль'),
                Tables\Filters\TernaryFilter::make('is_active')->label('Активність'),
            ])
            ->actions([
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            SynergiesRelationManager::class,
        ];
    }
}
