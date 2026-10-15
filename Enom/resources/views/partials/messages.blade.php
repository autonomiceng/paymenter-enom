@if (session('enom_status'))
    <p class="border border-neutral p-4 rounded-lg" role="status">{{ session('enom_status') }}</p>
@endif
@if ($errors->any())
    <div class="border border-red-500 p-4 rounded-lg space-y-1" role="alert">
        @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
    </div>
@endif
