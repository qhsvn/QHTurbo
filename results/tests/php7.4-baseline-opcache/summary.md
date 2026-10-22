# PHPT results — PHP 7.4 baseline (opcache)

- Date: 2026-10-22 08:34 UTC
- CPU: Intel(R) Xeon(R) Processor @ 2.80GHz (4 vCPU)
- RAM: 15 GiB
- OS: Ubuntu 24.04.5 LTS, kernel 6.18.44-fc-v140
- Compiler: gcc (Ubuntu 13.3.0-6ubuntu2~24.04.1) 13.3.0
- Upstream: php-7.4.33 (dca8d5565b947270a29f232bc54efd9df3b92b94, support: eol)
- QHturbo repo: 75b0737
- Binary sha256: fb5bc5a29573f7f7ef6ce6c0d17fef74b1e896b0752d419294bb830aae338ba3
- Test dirs: full suite of built extensions

```
TEST RESULT SUMMARY
---------------------------------------------------------------------
Exts skipped    :   60
Exts tested     :   13
---------------------------------------------------------------------

Number of tests : 15792              9073
Tests skipped   : 6719 ( 42.5%) --------
Tests warned    :    0 (  0.0%) (  0.0%)
Tests failed    :   46 (  0.3%) (  0.5%)
Expected fail   :   30 (  0.2%) (  0.3%)
Tests passed    : 8997 ( 57.0%) ( 99.2%)
---------------------------------------------------------------------
Time taken      :  122 seconds
=====================================================================
```

## Failed tests

```
Test stream_isatty with redirected STDERR [tests/output/stream_isatty_err.phpt]
Test stream_isatty with redirected STDIN/STDERR [tests/output/stream_isatty_in-err.phpt]
Test stream_isatty with redirected STDIN/STDOUT [tests/output/stream_isatty_in-out.phpt]
Test stream_isatty with redirected STDOUT/STDERR [tests/output/stream_isatty_out-err.phpt]
Test stream_isatty with redirected STDOUT [tests/output/stream_isatty_out.phpt]
Bug #78937.3 (Preloading unlinkable anonymous class can segfault) [ext/opcache/tests/bug78937_3.phpt]
Bug #78937.4 (Preloading unlinkable anonymous class can segfault) [ext/opcache/tests/bug78937_4.phpt]
Bug #78937.5 (Preloading unlinkable anonymous class can segfault) [ext/opcache/tests/bug78937_5.phpt]
Bug #78937.6 (Preloading unlinkable anonymous class can segfault) [ext/opcache/tests/bug78937_6.phpt]
Bug #78014 (Preloaded classes may depend on non-preloaded classes due to unresolved consts) [ext/opcache/tests/bug78014.phpt]
Bug #78175 (Preloading segfaults at preload time and at runtime) [ext/opcache/tests/bug78175.phpt]
Bug #78175.2 (Preloading segfaults at preload time and at runtime) [ext/opcache/tests/bug78175_2.phpt]
Bug #78376 (Incorrect preloading of constant static properties) [ext/opcache/tests/bug78376.phpt]
Bug #78937.1 (Preloading unlinkable anonymous class can segfault) [ext/opcache/tests/bug78937_1.phpt]
Bug #78937.2 (Preloading unlinkable anonymous class can segfault) [ext/opcache/tests/bug78937_2.phpt]
Bug #79114 (Eval class during preload causes class to be only half available) [ext/opcache/tests/preload_bug79114.phpt]
Bug #78918: Class alias during preloading causes assertion failure [ext/opcache/tests/preload_class_alias.phpt]
Bug #78918.2: Class alias during preloading causes assertion failure [ext/opcache/tests/preload_class_alias_2.phpt]
Defining a dynamic function inside the preload script [ext/opcache/tests/preload_dynamic_function.phpt]
Bug #81353: Segfault with preloading and error handler using static variables [ext/opcache/tests/preload_error_handler.phpt]
Preloading: Loadable class checking (1) [ext/opcache/tests/preload_loadable_classes_1.phpt]
Preloading: Loadable class checking (2) [ext/opcache/tests/preload_loadable_classes_2.phpt]
Preloading: Loadable class checking (3) [ext/opcache/tests/preload_loadable_classes_3.phpt]
Bug #79548: Preloading segfault with inherited method using static variable [ext/opcache/tests/preload_static_var_inheritance.phpt]
Op array fixed up multiple times during preloading [ext/opcache/tests/preload_trait_multiple_fixup.phpt]
Preload trait with static variables in method [ext/opcache/tests/preload_trait_static.phpt]
Preload: Unresolved property type [ext/opcache/tests/preload_unresolved_prop_type.phpt]
Preloading basic test [ext/opcache/tests/preload_001.phpt]
Preloading prototypes [ext/opcache/tests/preload_002.phpt]
Preloading classes linked with traits [ext/opcache/tests/preload_003.phpt]
Preloading class with undefined class constant access [ext/opcache/tests/preload_004.phpt]
Handling of auto globals during preloading [ext/opcache/tests/preload_005.phpt]
Handling of errors during linking [ext/opcache/tests/preload_006.phpt]
Handling of includes that were not executed [ext/opcache/tests/preload_007.phpt]
Preloading of anonymous class [ext/opcache/tests/preload_008.phpt]
Preloading class using trait with undefined class constant access [ext/opcache/tests/preload_009.phpt]
Initializer of overwritten property should be resolved against the correct class [ext/opcache/tests/preload_010.phpt]
Argument/return types must be available for preloading [ext/opcache/tests/preload_011.phpt]
No autoloading during constant resolution [ext/opcache/tests/preload_012.phpt]
Nested function definition [ext/opcache/tests/preload_013.phpt]
Bug #80067 (Omitting the port in bindto setting errors) [ext/standard/tests/network/bug80067.phpt]
Bug #81618: dns_get_record failure on FreeBSD [ext/standard/tests/network/bug81618.phpt]
gethostbyname() function - basic return valid ip address test [ext/standard/tests/network/gethostbyname_error004.phpt]
getmxrr() test [ext/standard/tests/network/getmxrr.phpt]
Testing ftell() on std streams [sapi/cli/tests/std_streams.phpt]
Bug #81145 (copy() and stream_copy_to_stream() fail for +4GB files) [ext/standard/tests/file/bug81145.phpt]
```
