<?php

namespace App\Filament\Concerns;

use Filament\Actions\Action;

/**
 * Create pages: "Create" opens the new record for editing, "Create and close" returns
 * to the list. Both are shown below the form and in the page header.
 */
trait HasCreateAndCloseActions
{
    /** Set for the current request only, so a failed create does not close the page later. */
    protected bool $closeAfterCreate = false;

    public function createAndClose(): void
    {
        $this->closeAfterCreate = true;
        $this->create();
    }

    protected function getRedirectUrl(): string
    {
        return $this->closeAfterCreate ? static::getResource()::getUrl('index') : parent::getRedirectUrl();
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            $this->getCreateAndCloseFormAction(),
            ...(static::canCreateAnother() ? [$this->getCreateAnotherFormAction()] : []),
            $this->getCancelFormAction(),
        ];
    }

    protected function getCreateAndCloseFormAction(): Action
    {
        return Action::make('createAndClose')
            ->label(__('Create and close'))
            ->action('createAndClose')
            ->color('gray');
    }

    protected function getHeaderActions(): array
    {
        return [
            // "Create" submits the form below via its id.
            $this->getCreateFormAction()->formId('form'),
            $this->getCreateAndCloseFormAction(),
        ];
    }
}
