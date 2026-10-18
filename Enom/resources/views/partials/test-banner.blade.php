@if (!empty($test))
    <div class="enom-testbar" role="note"><x-ri-flask-line class="enom-i" aria-hidden="true" /><span><strong>Test mode:</strong> @if ($test === 'some')Some endings use the registrar's test system, so those names aren't registered for real.@else Orders and changes use the registrar's test system. Nothing is registered for real.@endif</span></div>
@endif
