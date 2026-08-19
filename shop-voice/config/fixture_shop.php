<?php
declare(strict_types=1);

/**
 * Sample shop data for SHOPMONKEY_MODE=fixture.
 *
 * Shaped to exercise the hard cases rather than the happy path:
 *   - two open Tahoes, so "the Tahoe" must ask instead of guessing (§5);
 *   - one silver and one white, so "the silver Tahoe" resolves cleanly;
 *   - brake work recorded as "Front pads and rotors", "BR-FRT" and "Brake job"
 *     across three visits, so category matching cannot cheat with strcmp (§5);
 *   - a declined and a deferred service, which both feed the deferred badge (§8, §11).
 */

use ShopVoice\Shopmonkey\Customer;
use ShopVoice\Shopmonkey\HistoryEntry;
use ShopVoice\Shopmonkey\Order;
use ShopVoice\Shopmonkey\ServiceLine;
use ShopVoice\Shopmonkey\Vehicle;

$henderson = new Customer('cus_hend', 'Marcus Henderson', '517-555-0142');
$wozniak   = new Customer('cus_wozn', 'Dana Wozniak', '517-555-0187');
$delgado   = new Customer('cus_delg', 'Ray Delgado', '517-555-0119');
$nguyen    = new Customer('cus_nguy', 'Thu Nguyen', '517-555-0163');

$hendersonTahoe = new Vehicle(
    'veh_tahoe_silver', 2019, 'Chevrolet', 'Tahoe', 'Silver',
    '1GNSKBKC7KR118842', 'DWT 4419', '5.3L V8'
);
$wozniakTahoe = new Vehicle(
    'veh_tahoe_white', 2021, 'Chevrolet', 'Tahoe', 'White',
    '1GNSKNKD4MR253907', 'BRK 8820', '5.3L V8'
);
$delgadoF150 = new Vehicle(
    'veh_f150', 2016, 'Ford', 'F-150', 'Blue',
    '1FTEW1EF9GFA61177', 'MI 77321', '5.0L V8'
);
$nguyenCivic = new Vehicle(
    'veh_civic', 2018, 'Honda', 'Civic', 'Grey',
    '2HGFC2F59JH542118', 'CVC 2210', '2.0L I4'
);

$orders = [
    new Order(
        id: 'ord_4471',
        number: '4471',
        status: 'In Progress',
        open: true,
        vehicle: $hendersonTahoe,
        customer: $henderson,
        concern: 'Brake pedal pulses under hard braking on the highway. Started about two weeks ago, worse when the truck is loaded.',
        services: [
            new ServiceLine('svc_4471_1', 'Diagnose brake pulsation', 'Road test with the customer present.', total: 89.00),
            new ServiceLine('svc_4471_2', 'Front pads and rotors', null, total: 612.40),
            new ServiceLine('svc_4471_3', 'Rear differential fluid', 'Recommended at last visit.', authorized: false, deferred: true, total: 148.00),
        ],
        odometer: 96420,
        createdAt: '2026-08-17T13:40:00Z',
    ),
    new Order(
        id: 'ord_4472',
        number: '4472',
        status: 'Awaiting Parts',
        open: true,
        vehicle: $wozniakTahoe,
        customer: $wozniak,
        concern: 'Check engine light on. Runs fine, no noticeable drivability issue.',
        services: [
            new ServiceLine('svc_4472_1', 'Diagnose check engine light', 'P0455 stored — large evap leak.', total: 89.00),
            new ServiceLine('svc_4472_2', 'Evap canister purge valve', null, total: 264.75),
        ],
        odometer: 41180,
        createdAt: '2026-08-18T08:05:00Z',
    ),
    new Order(
        id: 'ord_4468',
        number: '4468',
        status: 'In Progress',
        open: true,
        vehicle: $delgadoF150,
        customer: $delgado,
        concern: 'Steering wander at highway speed and uneven tire wear on the front.',
        services: [
            new ServiceLine('svc_4468_1', 'Four wheel alignment', null, total: 129.95),
            new ServiceLine('svc_4468_2', 'Outer tie rod ends, both sides', null, total: 388.20),
            new ServiceLine('svc_4468_3', 'Front tires, pair', 'Customer declined — will source own tires.', authorized: false, declined: true, total: 420.00),
        ],
        odometer: 138902,
        createdAt: '2026-08-16T09:15:00Z',
    ),
    new Order(
        id: 'ord_4475',
        number: '4475',
        status: 'Scheduled',
        open: true,
        vehicle: $nguyenCivic,
        customer: $nguyen,
        concern: 'Oil change and a look at the AC — not as cold as it used to be.',
        services: [
            new ServiceLine('svc_4475_1', 'Full synthetic oil and filter', null, total: 79.95),
            new ServiceLine('svc_4475_2', 'AC performance test', null, total: 64.00),
        ],
        odometer: 74310,
        createdAt: '2026-08-18T07:30:00Z',
    ),

    // A closed order, so scope widening has something to find.
    new Order(
        id: 'ord_4310',
        number: '4310',
        status: 'Invoiced',
        open: false,
        vehicle: $hendersonTahoe,
        customer: $henderson,
        concern: 'Grinding noise from the front when stopping.',
        services: [
            new ServiceLine('svc_4310_1', 'BR-FRT', 'Pads and rotors, front.', total: 588.10),
        ],
        odometer: 94010,
        createdAt: '2026-07-11T14:20:00Z',
        closedAt: '2026-07-12T16:02:00Z',
    ),
];

$history = [
    'veh_tahoe_silver' => [
        new HistoryEntry('ord_4310', '4310', '2026-07-11', 94010, ['BR-FRT']),
        new HistoryEntry('ord_4102', '4102', '2026-03-04', 88240, ['Front pads and rotors', 'Full synthetic oil and filter']),
        new HistoryEntry('ord_3980', '3980', '2025-11-19', 81500, ['Full synthetic oil and filter', 'Tire rotation']),
        new HistoryEntry('ord_3712', '3712', '2025-05-02', 72110, ['Serpentine belt', 'Coolant flush']),
    ],
    'veh_tahoe_white' => [
        new HistoryEntry('ord_4201', '4201', '2026-05-22', 36400, ['Full synthetic oil and filter']),
    ],
    'veh_f150' => [
        new HistoryEntry('ord_4188', '4188', '2026-04-30', 131200, ['Brake job', 'Full synthetic oil and filter']),
        new HistoryEntry('ord_3901', '3901', '2025-09-14', 121880, ['Front struts, pair']),
    ],
    'veh_civic' => [
        new HistoryEntry('ord_4055', '4055', '2026-01-28', 68900, ['Full synthetic oil and filter', 'Cabin air filter']),
    ],
];

return ['orders' => $orders, 'history' => $history];
