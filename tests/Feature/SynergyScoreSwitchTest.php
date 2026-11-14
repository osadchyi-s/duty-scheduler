<?php

use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\RelationManagers\SynergiesRelationManager;
use App\Models\Synergy;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\EditAction;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
    $this->admin = User::create(['name' => 'Адмін', 'email' => 'admin@test.local', 'password' => bcrypt('secret'), 'is_active' => true]);
    $this->admin->assignRole('Admin');
    $this->other = User::create(['name' => 'Колега', 'email' => 'colleague@test.local', 'password' => bcrypt('secret'), 'is_active' => true]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->admin);
});

function synergyManager(User $owner)
{
    return Livewire::test(SynergiesRelationManager::class, [
        'ownerRecord' => $owner,
        'pageClass' => EditUser::class,
    ]);
}

it('stores the selected switch state as an integer score', function () {
    synergyManager($this->admin)
        ->mountTableAction(CreateAction::class)
        ->assertTableActionDataSet(['score' => '0'])
        ->setTableActionData(['other_user_id' => $this->other->id, 'score' => '-1'])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect(Synergy::sole()->score)->toBe(-1);
});

it('rejects scores outside the three states', function () {
    synergyManager($this->admin)
        ->callTableAction(CreateAction::class, data: ['other_user_id' => $this->other->id, 'score' => '5'])
        ->assertHasTableActionErrors(['score']);

    expect(Synergy::count())->toBe(0);
});

it('shows a legacy score by its sign', function () {
    $synergy = Synergy::create(['user_a_id' => $this->admin->id, 'user_b_id' => $this->other->id, 'score' => 7]);

    synergyManager($this->admin)
        ->mountTableAction(EditAction::class, $synergy)
        ->assertTableActionDataSet(['score' => '1']);
});

it('renders the score switch as three colored buttons without text', function () {
    synergyManager($this->admin)
        ->mountTableAction(CreateAction::class)
        ->assertSeeHtml('role="radiogroup"')
        ->assertSeeHtml('rgb(var(--danger-500))')
        ->assertSeeHtml('rgb(var(--gray-400))')
        ->assertSeeHtml('rgb(var(--success-500))')
        ->assertDontSee(['Negative', 'Neutral', 'Positive', 'Негативна', 'Нейтральна', 'Позитивна']);
});
