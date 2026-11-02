<?php
declare(strict_types=1);

// Keep the weather map inside the GaugeIQ dashboard, including legacy links.
header('Location: ../?openWeatherMap=1', true, 302);
exit;
