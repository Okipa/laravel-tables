<?php

namespace Okipa\LaravelTable\RowActions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Component;
use Okipa\LaravelTable\Abstracts\AbstractRowAction;

class RedirectRowAction extends AbstractRowAction
{
    public function __construct(
        public string $url,
        public string $title,
        public string $icon,
        public array $class = ['link-info'],
        public string|null $defaultConfirmationQuestion = null,
        public string|null $defaultFeedbackMessage = null,
        public bool $openInNewWindow = false,
    ) {
        //
    }

    protected function identifier(): string
    {
        return 'row_action_' . Str::snake($this->title);
    }

    protected function class(Model $model): array
    {
        return $this->class;
    }

    protected function icon(Model $model): string
    {
        return $this->icon;
    }

    protected function title(Model $model): string
    {
        return $this->title;
    }

    protected function defaultConfirmationQuestion(Model $model): string|null
    {
        return $this->defaultConfirmationQuestion;
    }

    protected function defaultFeedbackMessage(Model $model): string|null
    {
        return $this->defaultFeedbackMessage;
    }

    public function action(Model $model, Component $livewire): void
    {
        $this->openInNewWindow
            ? $livewire->dispatch('laraveltable:link:open:newtab', $this->url)
            : redirect()->to($this->url);
    }
}
