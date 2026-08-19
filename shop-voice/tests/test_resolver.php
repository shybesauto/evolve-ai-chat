<?php
declare(strict_types=1);

use ShopVoice\Resolver\RoResolver;
use ShopVoice\Resolver\Scope;
use ShopVoice\Resolver\SpokenNumbers;
use ShopVoice\Shopmonkey\FixtureShopmonkeyGateway;
use ShopVoice\Tests\Harness;

Harness::group('§5 Resolver cascade');

$resolver = new RoResolver(new FixtureShopmonkeyGateway());

$result = $resolver->resolve('pull up RO 4471');
Harness::same('ro_number', $result->matchedBy, 'RO number matches on the first rung');
Harness::same('4471', $result->order->number, 'and returns immediately, without asking');

$result = $resolver->resolve('vin 1GNSKBKC7KR118842');
Harness::same('vin', $result->matchedBy, 'a full VIN resolves');

$result = $resolver->resolve('last six is 118842');
Harness::same('vin_suffix', $result->matchedBy, 'VIN last six resolves');
Harness::same('4471', $result->order->number, 'to the right truck');

$result = $resolver->resolve('plate MI 77321');
Harness::same('plate', $result->matchedBy, 'a plate resolves');

$result = $resolver->resolve('the order for Henderson');
Harness::same('customer_name', $result->matchedBy, 'a customer name resolves');

$result = $resolver->resolve('the silver Tahoe');
Harness::same('vehicle_description', $result->matchedBy, 'a vehicle description resolves');
Harness::same('4471', $result->order->number, 'and colour picks the right one of two Tahoes');

// The rule the whole cascade exists for.
$result = $resolver->resolve('the Tahoe');
Harness::same('ambiguous', $result->outcome, 'two open Tahoes do not resolve to a guess');
Harness::same(2, count($result->candidates), 'both candidates come back');
Harness::contains('Henderson', (string) $result->question, 'the question names the owners');
Harness::contains('Wozniak', (string) $result->question, 'both of them');

$result = $resolver->resolve('RO 9999');
Harness::same('not_found', $result->outcome, 'an unknown RO is reported, not guessed at');

Harness::group('§5 Scope defaulting');

Harness::same(true, Scope::wantsHistory('when did we last do brakes on this one'), '"last" widens scope to closed orders');
Harness::same(true, Scope::wantsHistory('any history on this truck'), '"history" widens scope');
Harness::same(false, Scope::wantsHistory('pull up the silver Tahoe'), 'a plain lookup stays on open orders');

// RO 4310 is closed; the default open-only pass must still find it rather than
// telling a tech his own closed ticket does not exist.
$result = $resolver->resolve('RO 4310');
Harness::same('resolved', $result->outcome, 'a closed RO is found by widening');
Harness::same(true, $result->scopeWidened, 'and the widening is reported');

Harness::group('Spoken numbers');

Harness::same('RO 4471', SpokenNumbers::digitize('RO forty four seventy one'), '"forty four seventy one" is 4471');
Harness::same('RO 4471', SpokenNumbers::digitize('RO four four seven one'), 'digit-by-digit works too');
Harness::same('just one second', SpokenNumbers::digitize('just one second'), 'a lone number word stays a word');
