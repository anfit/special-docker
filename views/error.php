<?php

declare(strict_types=1);

/** @var int $status */
/** @var string $error */
?>
<h1><?= (int) $status ?></h1>
<p><?= special_escape($error) ?></p>
<p><a href="/specs">Return to specs</a></p>

