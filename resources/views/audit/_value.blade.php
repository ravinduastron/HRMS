@if ($v === config('audit.mask'))
    <span class="italic text-slate-400">value hidden</span>
@elseif ($v === null || $v === '')
    <span class="text-slate-300">—</span>
@elseif (is_array($v) || is_bool($v))
    <code class="break-all text-xs">{{ json_encode($v, JSON_UNESCAPED_UNICODE) }}</code>
@else
    <span class="break-words">{{ $v }}</span>
@endif
