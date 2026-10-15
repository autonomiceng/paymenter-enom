<label class="enom-check text-sm">
    <input type="checkbox" name="confirmed" value="1" required>
    <span>{{ $confirmation ?? 'I confirm these changes.' }}</span>
</label>
<p data-enom-client-error data-enom-client-for="confirmed" class="text-red-500 text-xs mt-1" hidden></p>
