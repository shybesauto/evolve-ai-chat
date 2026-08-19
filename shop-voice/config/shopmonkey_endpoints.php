<?php
declare(strict_types=1);

/**
 * Shopmonkey wire configuration — THE ONLY FILE THAT ENCODES ASSUMPTIONS ABOUT
 * THEIR API.
 *
 * Every entry below is marked `verified`. Everything is currently false: the
 * public docs cover eleven objects but not the exact request shapes we need,
 * and §2 of the spec is explicitly a discovery step that has not been run
 * (Russ's escalation to Shopmonkey is unanswered, and this build environment
 * has no outbound access to their API).
 *
 * The workflow is:
 *   1. Russ runs `php tools/probe_shopmonkey.php --token=...`
 *   2. The probe writes docs/shopmonkey-api-findings.md
 *   3. Whoever reviews it corrects the paths, methods and field names HERE
 *   4. `verified` flips to true and SHOPMONKEY_MODE=live becomes safe
 *
 * Nothing outside this file and LiveShopmonkeyGateway needs to change when the
 * findings land. That is the whole point of the fixture gateway: build steps
 * 1-5 do not block on step 0.
 */
return [
    'endpoints' => [
        'order.search'   => ['method' => 'POST', 'path' => 'order/search', 'verified' => false],
        'order.get'      => ['method' => 'GET',  'path' => 'order/{id}',   'verified' => false],
        'order.update'   => ['method' => 'PUT',  'path' => 'order/{id}',   'verified' => false],
        'vehicle.get'    => ['method' => 'GET',  'path' => 'vehicle/{id}', 'verified' => false],
        'user.list'      => ['method' => 'GET',  'path' => 'user',         'verified' => false],

        // §2 question 1. If a note resource exists at all, this is where it goes.
        // Leave verified=false and NOTE_STORE=local until proven otherwise.
        'note.create'    => ['method' => 'POST', 'path' => 'note',         'verified' => false],
        'note.list'      => ['method' => 'GET',  'path' => 'note',         'verified' => false],
    ],

    /**
     * Candidate key names, tried in order, for each field we need. Real payloads
     * from different API generations disagree about camelCase vs snake_case and
     * about nesting; reading defensively costs nothing and means an unexpected
     * shape degrades one field instead of throwing.
     */
    'fields' => [
        'order.id'          => ['id', 'orderId', 'uuid'],
        'order.number'      => ['number', 'orderNumber', 'name', 'invoiceNumber'],
        'order.status'      => ['status', 'orderStatus', 'workflowStatus'],
        'order.concern'     => ['complaint', 'customerConcern', 'concern', 'notes', 'description'],
        'order.odometer'    => ['mileage', 'odometer', 'currentMileage', 'mileageIn'],
        'order.createdAt'   => ['createdDate', 'createdAt', 'created'],
        'order.closedAt'    => ['invoicedDate', 'closedAt', 'completedDate'],
        'order.services'    => ['services', 'serviceItems', 'lineItems'],
        'order.vehicle'     => ['vehicle', 'vehicleData'],
        'order.customer'    => ['customer', 'customerData'],

        'service.id'        => ['id', 'serviceId'],
        'service.name'      => ['name', 'title', 'label'],
        'service.note'      => ['note', 'notes', 'description'],
        'service.authorized' => ['authorized', 'isAuthorized', 'approved'],
        'service.declined'  => ['declined', 'isDeclined'],
        'service.total'     => ['totalCost', 'total', 'amount'],

        'vehicle.id'        => ['id', 'vehicleId'],
        'vehicle.year'      => ['year', 'modelYear'],
        'vehicle.make'      => ['make', 'manufacturer'],
        'vehicle.model'     => ['model'],
        'vehicle.color'     => ['color', 'colour', 'exteriorColor'],
        'vehicle.vin'       => ['vin', 'VIN'],
        'vehicle.plate'     => ['licensePlate', 'plate', 'licensePlateNumber'],
        'vehicle.engine'    => ['engine', 'engineSize', 'engineDescription'],

        'customer.id'       => ['id', 'customerId'],
        'customer.name'     => ['fullName', 'name', 'displayName'],
        'customer.phone'    => ['phone', 'phoneNumber', 'primaryPhone'],

        'user.id'           => ['id', 'userId'],
        'user.name'         => ['fullName', 'name', 'displayName'],
        'user.email'        => ['email'],
        'user.role'         => ['role', 'userRole'],
        'user.cert'         => ['certificationNumber', 'certNumber', 'licenseNumber'],
    ],

    /** Where a list of records sits inside a response envelope. */
    'collection_keys' => ['data', 'results', 'records', 'items', 'rows'],
];
