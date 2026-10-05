<?php

require dirname(__DIR__) . '/vendor/autoload.php';

// Infection runs each mutant in a PHPUnit process whose bootstrap it generates. That bootstrap puts Infection's
// IncludeInterceptor on file://, so that including the original source file loads the mutated copy, and then
// requires this file. The interceptor registers itself again after every file operation, which drops the
// wrapper BypassFinals puts on top of it: the client's final classes would stay final, every test that doubles
// one would fail, and Infection would count the mutant as killed whatever the tests check. So when the
// interceptor serves file://, take its swap over: switch it off, enable BypassFinals on the plain file wrapper
// and load the mutated file through it, before anything asks for the original class. The interceptor is
// recognized by the class of the wrapper, so that the namespace-prefixed copy in Infection's PHAR is found too.
// The mutated class is then compiled from Infection's mutant file, with the original line numbers, so a
// breakpoint set in src/ is not hit in a mutant process.
$handle = fopen(__FILE__, 'r');
$wrapper = $handle === false ? null : (stream_get_meta_data($handle)['wrapper_data'] ?? null);
if ($handle !== false) {
    fclose($handle);
}

if (is_object($wrapper) && str_ends_with('\\' . $wrapper::class, '\\Infection\\StreamWrapper\\IncludeInterceptor')) {
    $interceptor = $wrapper::class;
    $original = (new ReflectionProperty($interceptor, 'intercept'))->getValue();
    $mutant = (new ReflectionProperty($interceptor, 'replacement'))->getValue();
    $interceptor::disable();
    DG\BypassFinals::enable();
    // PHPUnit requires vendor/autoload.php before the bootstrap Infection generates, so a source file that
    // Composer loads up front (a "files" entry) was loaded unmutated, before the interceptor was on. Requiring
    // the mutated copy too would be a fatal redeclaration, which Infection counts as a detected mutant. Without
    // it the tests run the original code, and Infection reports the mutant as escaped.
    if (!in_array($original, get_included_files(), true)) {
        require_once $mutant;
    }
} else {
    DG\BypassFinals::enable();
}
