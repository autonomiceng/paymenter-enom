@if (!empty($test))
    <div class="enom-testbar" role="note"><x-ri-flask-line class="enom-i" aria-hidden="true" /><span><strong>Test mode:</strong> @if ($test === 'some')Some endings use Enom's test environment.@else Orders and changes use Enom's test environment.@endif</span></div>
@endif
