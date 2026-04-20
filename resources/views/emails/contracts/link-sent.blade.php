<x-mail::message>
# {{ $type === 'quote' ? 'Tilbud på tjenester' : 'Kontrakt på tjenester' }}

Hei,

Vi har gleden av å sende deg {{ $type === 'quote' ? 'et tilbud' : 'en kontrakt' }} på våre tjenester.
Du kan gå gjennom detaljene og godkjenne dokumentet ved å trykke på knappen nedenfor.

<x-mail::button :url="$url">
Se {{ $type === 'quote' ? 'tilbud' : 'kontrakt' }}
</x-mail::button>

Hvis du har spørsmål, er det bare å svare på denne e-posten.

Vennlig hilsen,<br>
{{ config('app.name') }}
</x-mail::message>
