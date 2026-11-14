<?php

namespace App\Filament\Concerns;

use Filament\Actions\Action;

/**
 * Edit pages: "Save" keeps the user on the page, "Save and close" returns to the list.
 * Both are shown below the form and, via getSaveHeaderActions(), above it.
 */
trait HasSaveAndCloseActions
{
    /** Set for the current request only, so a failed save does not close the page later. */
    protected bool $closeAfterSave = false;

    public function saveAndClose(): void
    {
        $this->closeAfterSave = true;
        $this->save();
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->closeAfterSave ? static::getResource()::getUrl('index') : null;
    }

    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
            $this->getSaveAndCloseFormAction(),
            $this->getCancelFormAction(),
        ];
    }

    protected function getSaveAndCloseFormAction(): Action
    {
        return Action::make('saveAndClose')
            ->label(__('Save and close'))
            ->action('saveAndClose')
            ->color('gray');
    }

    /**
     * The same buttons for the page header; "Save" submits the form below via its id.
     *
     * @return array<int, Action>
     */
    protected function getSaveHeaderActions(): array
    {
        return [
            $this->getSaveFormAction()->formId('form'),
            $this->getSaveAndCloseFormAction(),
        ];
    }
}
