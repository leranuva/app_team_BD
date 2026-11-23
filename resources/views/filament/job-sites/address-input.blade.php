<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        class="job-site-address"
        data-address-path="{{ $getStatePath() }}"
        x-data="window.blueDraftJobSiteAddress($wire, $el.dataset.addressPath)"
        x-on:focusout="if (!$el.contains($event.relatedTarget)) close()"
    >
        <x-filament::input.wrapper :valid="! $errors->has($getStatePath())">
            <x-filament::input
                :id="$getId()"
                :required="$isRequired()"
                :maxlength="$getMaxLength()"
                :wire:model="$getStatePath()"
                type="text"
                autocomplete="off"
                placeholder="Start typing a street address"
                role="combobox"
                aria-autocomplete="list"
                aria-controls="{{ $getId() }}-suggestions"
                x-bind:aria-expanded="open"
                x-bind:aria-activedescendant="active < 0 ? null : $el.id + '-option-' + active"
                x-on:input="search($event.target.value)"
                x-on:keydown.arrow-down="if (open) { $event.preventDefault(); move(1) }"
                x-on:keydown.arrow-up="if (open) { $event.preventDefault(); move(-1) }"
                x-on:keydown.enter="if (open && active >= 0) { $event.preventDefault(); select(active) }"
                x-on:keydown.escape="if (open || loading) { $event.stopPropagation(); close() }"
            />
        </x-filament::input.wrapper>

        <ul
            id="{{ $getId() }}-suggestions"
            class="job-site-address-list"
            role="listbox"
            aria-label="Address suggestions"
            x-cloak
            x-show="open"
        >
            <template x-for="(result, index) in results" :key="index">
                <li
                    class="job-site-address-option"
                    x-bind:id="@js($getId().'-option-') + index"
                    role="option"
                    x-bind:aria-selected="active === index"
                    x-on:mouseenter="active = index"
                    x-on:mousedown.prevent
                    x-on:click="select(index)"
                    x-text="result.label"
                ></li>
            </template>
        </ul>
        <p class="job-site-address-status" role="status" x-cloak x-show="message" x-text="message"></p>
    </div>
</x-dynamic-component>
