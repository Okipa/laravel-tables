<?php

namespace Okipa\LaravelTable\RowActions;

use Illuminate\Database\Eloquent\Model;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;
use Okipa\LaravelTable\Abstracts\AbstractRowAction;

class EditRowAction extends AbstractRowAction
{
    public function __construct(public string $editUrl)
    {
        //
    }

    protected function identifier(): string
    {
        return 'row_action_edit';
    }

    protected function class(Model $model): array
    {
        return ['link-primary'];
    }

    protected function icon(Model $model): string
    {
        return config('laravel-table.icon.edit');
    }

    protected function title(Model $model): string
    {
        return __('Edit');
    }

    protected function defaultConfirmationQuestion(Model $model): string|null
    {
        return null;
    }

    protected function defaultFeedbackMessage(Model $model): string|null
    {
        return null;
    }

    public function action(Model $model, Component $livewire): Redirector
    {
        return redirect()->to($this->editUrl);
    }
}
