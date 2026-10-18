<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class SynergiesRelationManager extends RelationManager
{
    protected static string $relationship = 'synergies';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Synergy');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('other_user_id')
                ->label(__('Employee'))
                ->options(fn (): array => $this->counterpartUsers()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required()
                ->rules(fn (): array => [Rule::in($this->counterpartUsers()->pluck('id')->all())])
                ->visibleOn('create'),
            Forms\Components\ViewField::make('score')
                ->label(__('Synergy level'))
                ->view('filament.forms.components.synergy-score')
                ->default(0)
                ->rules(['required', 'integer']),
            Forms\Components\Select::make('eventTypes')
                ->label(__('Event types'))
                ->relationship('eventTypes', 'name')
                ->multiple()
                ->searchable()
                ->preload()
                ->helperText(__('If no event types are selected, synergy applies to all event types.')),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('other_employee')
                    ->label(__('Employee'))
                    ->state(fn ($record): string => (int) $record->user_a_id === (int) $this->getOwnerRecord()->getKey()
                        ? $record->userB->name
                        : $record->userA->name),
                Tables\Columns\TextColumn::make('score')
                    ->label(__('Synergy'))
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state < 0 => 'danger',
                        $state > 0 => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('eventTypes.name')
                    ->label(__('Event types'))
                    ->badge()
                    ->placeholder(__('All events')),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label(__('Add synergy'))
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
            ->emptyStateHeading(__('No synergies have been added yet'))
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['userA', 'userB', 'eventTypes']));
    }

    protected function counterpartUsers(): Builder
    {
        return User::query()
            ->whereKeyNot($this->getOwnerRecord()->getKey());
    }
}
