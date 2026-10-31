<?php

namespace App\Filament\Resources;

use App\Filament\Actions\DeleteAllowedBulkAction;
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
use App\Models\Role;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return __('Employees');
    }

    public static function getModelLabel(): string
    {
        return __('Employee');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Employees');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('People and access');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('Account'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')->label(__('Full name'))->required()->maxLength(255),
                    Forms\Components\TextInput::make('email')->label(__('Email'))->email()->required()
                        ->unique(ignoreRecord: true)->maxLength(255),
                    Forms\Components\TextInput::make('password')
                        ->label(__('Password'))
                        ->password()
                        ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                        ->dehydrated(fn ($state) => filled($state))
                        ->required(fn (string $context) => $context === 'create')
                        ->helperText(__('Leave blank to keep the current password')),
                    Forms\Components\TextInput::make('position')->label(__('Position'))->maxLength(255),
                    Forms\Components\Toggle::make('is_active')->label(__('Active'))->default(true),
                ]),

            Forms\Components\Section::make(__('Access role'))
                ->schema([
                    Forms\Components\Select::make('roles')
                        ->label(__('Role'))
                        ->relationship('roles', 'name')
                        ->options(function () {
                            // A Manager can assign only User/Manager roles, not Admin.
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

            Forms\Components\Section::make(__('Employee groups'))
                ->description(__('Groups this employee belongs to. Groups are used, for example, in duty limits.'))
                ->schema([
                    Forms\Components\Select::make('employeeGroups')
                        ->label(__('Groups'))
                        ->relationship('employeeGroups', 'name')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->createOptionForm([
                            Forms\Components\TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                            Forms\Components\Textarea::make('description')->label(__('Description'))->rows(2),
                        ]),
                ]),

            Forms\Components\Section::make(__('Event type permissions'))
                ->description(__('Event types and absences this employee may be assigned'))
                ->schema([
                    Forms\Components\CheckboxList::make('allowedEventTypes')
                        ->label(__('Allowed event types'))
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
                Tables\Columns\TextColumn::make('name')->label(__('Full name'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->label(__('Email'))->searchable(),
                Tables\Columns\TextColumn::make('position')->label(__('Position')),
                Tables\Columns\TextColumn::make('roles.name')->label(__('Roles'))->badge(),
                Tables\Columns\TextColumn::make('employeeGroups.name')->label(__('Groups'))->badge()->color('gray'),
                Tables\Columns\IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('roles')->relationship('roles', 'name')->label(__('Role')),
                Tables\Filters\SelectFilter::make('employeeGroups')->relationship('employeeGroups', 'name')->label(__('Group')),
                Tables\Filters\TernaryFilter::make('is_active')->label(__('Active status')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    DeleteAllowedBulkAction::make(),
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
