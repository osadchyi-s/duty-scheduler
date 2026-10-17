<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class SynergiesRelationManager extends RelationManager
{
    protected static string $relationship = 'synergies';

    protected static ?string $title = 'Синергія';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('other_user_id')
                ->label('Співробітник')
                ->options(fn (): array => $this->counterpartUsers()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required()
                ->rules(fn (): array => [Rule::in($this->counterpartUsers()->pluck('id')->all())])
                ->visibleOn('create'),
            Forms\Components\ViewField::make('score')
                ->label('Рівень синергії')
                ->view('filament.forms.components.synergy-score')
                ->default(0)
                ->rules(['required', 'integer']),
            Forms\Components\Select::make('eventTypes')
                ->label('Типи подій')
                ->relationship('eventTypes', 'name')
                ->multiple()
                ->searchable()
                ->preload()
                ->helperText('Якщо не вибрати типи подій, синергія застосовується до всіх.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('other_employee')
                    ->label('Співробітник')
                    ->state(fn ($record): string => (int) $record->user_a_id === (int) $this->getOwnerRecord()->getKey()
                        ? $record->userB->name
                        : $record->userA->name),
                Tables\Columns\TextColumn::make('score')
                    ->label('Синергія')
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state < 0 => 'danger',
                        $state > 0 => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('eventTypes.name')
                    ->label('Типи подій')
                    ->badge()
                    ->placeholder('Усі події'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Додати синергію')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['user_b_id'] = (int) $data['other_user_id'];
                        unset($data['other_user_id']);

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('Синергій ще не додано')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['userA', 'userB', 'eventTypes']));
    }

    protected function counterpartUsers(): Builder
    {
        return User::query()
            ->whereKeyNot($this->getOwnerRecord()->getKey());
    }
}
