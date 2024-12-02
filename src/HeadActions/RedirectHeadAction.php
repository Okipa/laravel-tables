<?php

namespace Okipa\LaravelTable\HeadActions;

use Livewire\Component;
use Okipa\LaravelTable\Abstracts\AbstractHeadAction;

class RedirectHeadAction extends AbstractHeadAction
{
    public function __construct(
        public string $url,
        public string $label,
        public string $icon,
        public array $class = ['btn', 'btn-success'],
        public bool $openInNewWindow = false,
    ) {
        //
    }

    protected function class(): array
    {
        return $this->class;
    }

    protected function title(): string
    {
        return __($this->label);
    }

    protected function icon(): string
    {
        return $this->icon;
    }

    public function action(Component $livewire): void
    {
        $this->openInNewWindow
            ? $livewire->dispatch('laraveltable:link:open:newtab', $this->url)
            : redirect()->to($this->url);
    }
}
