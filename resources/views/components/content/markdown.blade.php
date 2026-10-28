@props(['source' => null])

{{-- Shared safe operational Markdown; only already-authorized text reaches this component. --}}
<div {{ $attributes->class(['text-break']) }}>{!! app(\App\Modules\System\Actions\RenderOperationalMarkdown::class)->handle($source) !!}</div>
