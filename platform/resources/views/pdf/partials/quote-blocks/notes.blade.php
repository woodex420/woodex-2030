@if ($s['notes'] !== '')
    <h2>Notes</h2>
    <div>{!! nl2br(e($s['notes'])) !!}</div>
@endif
