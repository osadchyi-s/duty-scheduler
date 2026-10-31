<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Models\Synergy;
use App\Models\User;
use App\Support\AuditLog;
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

    /** Event types of a synergy are a BelongsToMany relation, logged explicitly. */
    protected const LOGGED_RELATIONS = ['eventTypes' => 'name'];

    /** @var array<string, array<int, string>> */
    protected array $relationsBeforeSave = [];

    protected const SCORE_NEGATIVE = -1;

    protected const SCORE_NEUTRAL = 0;

    protected const SCORE_POSITIVE = 1;

    protected const SCORES = [self::SCORE_NEGATIVE, self::SCORE_NEUTRAL, self::SCORE_POSITIVE];

    protected const SCORE_COLORS = [
        self::SCORE_NEGATIVE => 'rgb(var(--danger-500))',
        self::SCORE_NEUTRAL => 'rgb(var(--gray-400))',
        self::SCORE_POSITIVE => 'rgb(var(--success-500))',
    ];

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
                ->viewData(['scoreColors' => self::SCORE_COLORS])
                ->default((string) self::SCORE_NEUTRAL)
                // Older records may hold any integer; show them by their sign.
                ->formatStateUsing(fn ($state): string => (string) self::normalizeScore($state))
                ->dehydrateStateUsing(fn ($state): int => self::normalizeScore($state))
                ->rules([Rule::in(array_map('strval', self::SCORES))]),
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
                Tables\Columns\ColorColumn::make('score')
                    ->label(__('Synergy'))
                    ->getStateUsing(fn ($record): string => self::SCORE_COLORS[self::normalizeScore($record->score)]),
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
                    })
                    ->after(fn (Synergy $record) => AuditLog::recordRelationChanges($record, [], self::LOGGED_RELATIONS)),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->before(function (Synergy $record): void {
                        $this->relationsBeforeSave = AuditLog::relationSnapshot($record, self::LOGGED_RELATIONS);
                    })
                    ->after(fn (Synergy $record) => AuditLog::recordRelationChanges($record, $this->relationsBeforeSave, self::LOGGED_RELATIONS)),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading(__('No synergies have been added yet'))
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['userA', 'userB', 'eventTypes']));
    }

    /**
     * Synergy is a three-state value: negative (-1), neutral (0 or empty), positive (1).
     */
    protected static function normalizeScore(mixed $state): int
    {
        return $state === null || $state === '' ? self::SCORE_NEUTRAL : (int) ($state <=> 0);
    }

    protected function counterpartUsers(): Builder
    {
        return User::query()
            ->whereKeyNot($this->getOwnerRecord()->getKey());
    }
}
