<li wire:key="bulk-action-{{ Str::slug($bulkAction->identifier) }}">
    <button wire:click.prevent="bulkAction('{{ $bulkAction->identifier }}', {{ $bulkAction->getConfirmationQuestion() ? 1 : 0 }})"
            class="dropdown-item"
            title="{{ $label }}"
            type="button">
        {{ $label }}
    </button>
</li>