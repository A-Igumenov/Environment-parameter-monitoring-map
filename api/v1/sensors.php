<?php
/**
 * 2.4 API v1 — versioned endpoint.
 *
 * Currently v1 is the current stable API version. It forwards to
 * the main implementation so that old integrations (/api/sensors.php)
 * and new ones (/api/v1/sensors.php) behave identically. In the future one can
 * add /api/v2/ without changing v1 behavior.
 */
require __DIR__ . '/../sensors.php';
