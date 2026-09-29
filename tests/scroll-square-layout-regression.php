<?php
require_once __DIR__ . '/strict-skin-feature-helper.inc';
snapsmack_assert_strict_skin_feature('scroll');
echo "PASS: scroll behavior is CMS-owned and its presentation contract is strict.
";