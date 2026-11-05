<?php

use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\RelationManagers\SynergiesRelationManager;
use App\Models\Synergy;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
    $admin = User::create(['name' => 'Адмін', 'email' => 'admin@test.local', 'password' => 'secret-password']);
    $admin->assignRole('Admin');

    // The owner has the lowest id, so it is user A in every pair (pairs are stored with a < b).
    $this->owner = User::create(['name' => 'Власник', 'email' => 'owner@test.local', 'password' => 'secret-password']);
    $this->pairs = collect(['Перший', 'Другий', 'Третій'])->map(function (string $name, int $i) {
        $other = User::create(['name' => $name, 'email' => "other{$i}@test.local", 'password' => 'secret-password']);

        return Synergy::create(['user_a_id' => $this->owner->id, 'user_b_id' => $other->id, 'score' => 0]);
    });

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($admin);
});

function synergiesOf(User $owner)
{
    return Livewire::test(SynergiesRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => EditUser::class]);
}

it('edits the selected synergy, not the first one of the employee', function () {
    [$first, $second] = [$this->pairs[0], $this->pairs[1]];

    synergiesOf($this->owner)
        ->mountTableAction(EditAction::class, $second)
        ->assertTableActionDataSet(['score' => '0'])
        ->setTableActionData(['score' => '1'])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($second->fresh()->score)->toBe(1)
        ->and($first->fresh()->score)->toBe(0);
});

it('deletes the selected synergy, not the first one of the employee', function () {
    [$first, , $third] = $this->pairs->all();

    synergiesOf($this->owner)->callTableAction(DeleteAction::class, $third);

    expect(Synergy::find($third->id))->toBeNull()
        ->and(Synergy::find($first->id))->not->toBeNull();
});

it('lists and edits pairs where the employee is the second user', function () {
    $second = $this->pairs[1];
    $partner = User::find($second->user_b_id);

    synergiesOf($partner)
        ->assertCanSeeTableRecords([$second])
        ->assertCanNotSeeTableRecords([$this->pairs[0], $this->pairs[2]])
        ->callTableAction(EditAction::class, $second, data: ['score' => '-1']);

    expect($second->fresh()->score)->toBe(-1)
        ->and($this->pairs[0]->fresh()->score)->toBe(0);
});
