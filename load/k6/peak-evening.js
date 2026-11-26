// Peak evening: sellers placing orders in a burst at the close of a sales cycle.
//
//   k6 run -e BASE_URL=https://main-xxxx.eu-5.platformsh.site load/k6/peak-evening.js
//
// Options (environment variables):
//   BASE_URL   NFW URL (default http://127.0.0.1:8001)
//   PEAK_RPS   orders per second at the peak (default 40)
//   RAMP       ramp-up duration (default 1m)
//   HOLD       time at the peak (default 4m)
//   SELLERS    number of seeded sellers to pick from (default 50000)
//   TOKEN      value for X-Load-Test-Token, if LOAD_TEST_TOKEN is set on the environment
import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';

const BASE = __ENV.BASE_URL || 'http://127.0.0.1:8001';
const PEAK = Number(__ENV.PEAK_RPS || 40);
const SELLERS = Number(__ENV.SELLERS || 50000);
const SKUS = ['VIS-001', 'VIS-002', 'VIS-003', 'VIS-004', 'VIS-005', 'COR-001', 'COR-002', 'COR-003', 'COR-004', 'COR-005',
    'CAP-001', 'CAP-002', 'CAP-003', 'PRO-001', 'PRO-002', 'PRO-003', 'TRU-001', 'TRU-002', 'TRU-003', 'TRU-004'];

const ordersPlaced = new Counter('orders_placed');

export const options = {
    scenarios: {
        // Orders: the write path (NFW -> Promotion Engine -> MariaDB + outbox).
        orders: {
            executor: 'ramping-arrival-rate',
            exec: 'placeOrder',
            startRate: 2,
            timeUnit: '1s',
            preAllocatedVUs: 50,
            maxVUs: 400,
            stages: [
                { target: PEAK, duration: __ENV.RAMP || '1m' },
                { target: PEAK, duration: __ENV.HOLD || '4m' },
                { target: 0, duration: '30s' },
            ],
        },
        // Browsing: sellers looking up colleagues before ordering (read path).
        browse: {
            executor: 'ramping-arrival-rate',
            exec: 'browse',
            startRate: 2,
            timeUnit: '1s',
            preAllocatedVUs: 20,
            maxVUs: 200,
            stages: [
                { target: PEAK, duration: __ENV.RAMP || '1m' },
                { target: PEAK, duration: __ENV.HOLD || '4m' },
                { target: 0, duration: '30s' },
            ],
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        'http_req_duration{scenario:orders}': ['p(95)<1500'],
        'http_req_duration{scenario:browse}': ['p(95)<800'],
    },
};

const pick = (list) => list[Math.floor(Math.random() * list.length)];
const sellerCode = () => `INC-${String(1 + Math.floor(Math.random() * SELLERS)).padStart(6, '0')}`;

export function placeOrder() {
    const qty = {};
    const lines = 1 + Math.floor(Math.random() * 4);
    for (let i = 0; i < lines; i++) qty[pick(SKUS)] = 1 + Math.floor(Math.random() * 3);

    const res = http.post(`${BASE}/api/ordini`, JSON.stringify({ seller_code: sellerCode(), qty }), {
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Load-Test-Token': __ENV.TOKEN || '' },
        tags: { name: 'POST /api/ordini' },
    });
    if (check(res, { 'order created': (r) => r.status === 201 })) ordersPlaced.add(1);
}

export function browse() {
    const q = pick(['Rossi', 'Bianchi', 'Pontedera', 'Pisa', 'Lucca', 'Firenze', 'Greco']);
    const res = http.get(`${BASE}/accedi?q=${q}`, { tags: { name: 'GET /accedi' } });
    check(res, { 'page ok': (r) => r.status === 200 });
}
