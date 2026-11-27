<?php

namespace App\Support\Products;

use RuntimeException;

/**
 * A product database could not be asked (not reachable, too slow, limit reached).
 */
class SourceUnavailable extends RuntimeException {}
