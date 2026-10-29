<?php

namespace App\Filament\Actions;

use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteBulkAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Bulk delete that skips records the policy does not allow deleting (e.g. event
 * types or employees that still have events) instead of failing on the
 * database restriction.
 */
class DeleteAllowedBulkAction extends DeleteBulkAction
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->action(function (): void {
            $skipped = 0;

            $this->process(function (Collection $records) use (&$skipped): void {
                foreach ($records as $record) {
                    /** @var Model $record */
                    if (auth()->user()?->can('delete', $record)) {
                        $record->delete();
                    } else {
                        $skipped++;
                    }
                }
            });

            if ($skipped > 0) {
                Notification::make()
                    ->title(__('Some records were not deleted'))
                    ->body(__('Records with events cannot be deleted: :count. Deactivate them instead.', ['count' => $skipped]))
                    ->warning()
                    ->send();

                return;
            }

            $this->success();
        });
    }
}
