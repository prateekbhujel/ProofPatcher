<?php

declare(strict_types=1);

// Rate limits must work on local installations that have no database cache table.
return [
    'limiter' => 'file',
];
