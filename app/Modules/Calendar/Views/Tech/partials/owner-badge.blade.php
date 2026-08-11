@php($ownerDescription = 'Owner: '.$event['owner_label'].'. '.$event['calendar_type_label'].' calendar.')

<!-- Privacy-safe Calendar owner identity -->
<span class="calendar-owner-badge badge rounded-pill border flex-shrink-0"
    aria-label="{{ $ownerDescription }}" title="{{ $ownerDescription }}"
    data-calendar-owner-badge="{{ $event['owner_badge'] }}">
    <span class="calendar-owner-swatch" style="--owner-color: {{ $event['calendar_color'] }}" aria-hidden="true"></span>
    <span aria-hidden="true">{{ $event['owner_badge'] }}</span>
</span>
