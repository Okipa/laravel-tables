<?php

namespace Okipa\LaravelTable\ColumnActions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Livewire\Component;
use Okipa\LaravelTable\Abstracts\AbstractColumnAction;

class ToggleEmailVerifiedColumnAction extends AbstractColumnAction
{
    protected function class(Model $model, string $attribute): array
    {
        return [
            'link-success' => $model->{$attribute},
            'link-danger' => ! $model->{$attribute},
        ];
    }

    protected function icon(Model $model, string $attribute): string
    {
        return $model->{$attribute}
            ? config('laravel-table.icon.email_verified')
            : config('laravel-table.icon.email_unverified');
    }

    protected function title(Model $model, string $attribute): string
    {
        return $model->{$attribute} ? __('Unverify') : __('Verify');
    }

    protected function label(Model $model, string $attribute): string|null
    {
        return null;
    }

    protected function defaultConfirmationQuestion(Model $model, string $attribute): string|null
    {
        return __('Are you sure you want to set the field :attribute as :action for the line #:primary?', [
            'attribute' => __('validation.attributes.' . $attribute),
            'action' => $model->{$attribute} ? __('unverified') : __('verified'),
            'primary' => $model->getKey(),
        ]);
    }

    protected function defaultFeedbackMessage(Model $model, string $attribute): string|null
    {
        return __('The field :attribute from the line #:primary has been :action.', [
            'attribute' => __('validation.attributes.' . $attribute),
            'primary' => $model->getKey(),
            'action' => $model->{$attribute} ? __('unverified') : __('verified'),
        ]);
    }

    public function action(Model $model, string $attribute, Component $livewire): void
    {
        // Update attribute event if it not in model `$fillable`.
        $model->forceFill([$attribute => $model->{$attribute} ? null : Date::now()])->save();
    }
}
