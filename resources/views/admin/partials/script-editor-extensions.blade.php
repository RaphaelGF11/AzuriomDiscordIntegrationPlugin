{{--
    Other plugins' own script block types (see Support\ScriptExtensions) -
    must be included AFTER command-builder-scripts.blade.php (so
    window.CommandBuilder.registerActionType()/registerConditionType()
    already exist for these to call) and BEFORE the page's own
    ScriptEditor.init() call (so the palette's first render already sees
    them) - see command-script.blade.php/form-script.blade.php's include
    order for where this sits between the two.
--}}
@push('footer-scripts')
    @foreach($scriptExtensionAssets ?? [] as $assetUrl)
        <script src="{{ $assetUrl }}"></script>
    @endforeach
@endpush
