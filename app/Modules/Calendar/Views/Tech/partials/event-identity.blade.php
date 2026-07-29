<!-- Calendar owner and type are separate, redundant identity signals -->
<span class="calendar-event-identity">
    @include('calendar::Tech.partials.owner-badge', ['event' => $event])
    @include('calendar::Tech.partials.type-badge', ['event' => $event])
</span>
