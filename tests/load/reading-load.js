/**
 * 3.4 Load test (k6): simulates sensor traffic.
 *
 * Project limit: up to 49,800 sensors, each sending once per 5 min
 * (166 INSERT/sec). This test checks whether the server withstands the target
 * write rate.
 *
 * Paleidimas:
 *   k6 run -e BASE_URL=http://localhost/iot tests/load/reading-load.js
 *
 * The defined scenarios simulate 166 readings/sec (the target peak).
 */

import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE = __ENV.BASE_URL || 'http://localhost/iot';

export const options = {
  scenarios: {
    // Target peak: ~166 writes/sec for a minute
    target_peak: {
      executor: 'constant-arrival-rate',
      rate: 166,            // 166 requests
      timeUnit: '1s',       // per second
      duration: '1m',
      preAllocatedVUs: 50,
      maxVUs: 200,
    },
  },
  thresholds: {
    http_req_duration: ['p(95)<500'],  // 95% < 500ms
    http_req_failed: ['rate<0.05'],    // < 5% errors
  },
};

export default function () {
  // Random sensor (simulating different MACs)
  const n = Math.floor(Math.random() * 49800);
  const mac = 'AA:BB:CC:' +
    ((n >> 16) & 0xff).toString(16).padStart(2, '0') + ':' +
    ((n >> 8) & 0xff).toString(16).padStart(2, '0') + ':' +
    (n & 0xff).toString(16).padStart(2, '0');
  const lat = (54 + Math.random()).toFixed(7);
  const lng = (25 + Math.random()).toFixed(7);

  const url = `${BASE}/api/sensors.php?action=reading` +
    `&lat=${lat}&lng=${lng}&mac=${mac}` +
    `&temperature=${(15 + Math.random() * 15).toFixed(1)}` +
    `&humidity=${(40 + Math.random() * 40).toFixed(1)}`;

  const res = http.get(url);
  check(res, {
    'status < 500': (r) => r.status < 500,
  });
  sleep(0.1);
}
