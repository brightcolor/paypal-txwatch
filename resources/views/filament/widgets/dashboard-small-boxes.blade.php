<x-filament-widgets::widget>
    <div class="lte-small-boxes">
        @foreach ($boxes as $box)
            <div class="lte-small-box lte-bg-{{ $box['color'] }}">
                <div class="inner">
                    <div class="value">{{ $box['value'] }}</div>
                    <div class="label">{{ $box['label'] }}</div>
                </div>
                <x-filament::icon :icon="$box['icon']" class="icon" />
                @if ($box['url'])
                    <a class="more" href="{{ $box['url'] }}">
                        Mehr Infos <span aria-hidden="true">&rarr;</span>
                    </a>
                @else
                    <span class="more" style="opacity:.55;">&nbsp;</span>
                @endif
            </div>
        @endforeach
    </div>
</x-filament-widgets::widget>
