@if(! empty($closedNotificationTags))
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (! navigator.serviceWorker || ! navigator.serviceWorker.controller) {
                return;
            }

            navigator.serviceWorker.controller.postMessage({
                type: 'nexum-close-notifications',
                tags: @json(array_values($closedNotificationTags)),
            });
        });
    </script>
@endif
