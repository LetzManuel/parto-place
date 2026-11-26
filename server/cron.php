<?php
declare(strict_types=1);

// Polls all connected cloud vendors. Run by the hosting cron job, e.g. every 5 minutes:
//   php /pfad/zu/place/cron.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/src/bootstrap.php';

[, $service] = Place\make_app(Place\load_config());
$service->pollAll();
