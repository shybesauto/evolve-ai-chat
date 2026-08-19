<?php
declare(strict_types=1);

use ShopVoice\Intent\ActionCatalog;
use ShopVoice\Intent\Intent;
use ShopVoice\Intent\IntentContext;
use ShopVoice\Intent\IntentTarget;
use ShopVoice\Intent\IntentValidator;
use ShopVoice\Intent\Providers\HostedProvider;
use ShopVoice\Intent\Providers\RuleBasedProvider;
use ShopVoice\Intent\RiskTier;
use ShopVoice\Intent\ValidationResult;
use ShopVoice\Tests\Harness;

Harness::group('§4 Closed action list');

Harness::same(RiskTier::Read, ActionCatalog::tier('get_order'), 'get_order is a read');
Harness::same(RiskTier::LowRiskWrite, ActionCatalog::tier('add_note'), 'add_note is a low-risk write');
Harness::same(RiskTier::HighRisk, ActionCatalog::tier('set_status'), 'set_status is high-risk');
Harness::same(RiskTier::HighRisk, ActionCatalog::tier('edit_price'), 'edit_price is high-risk');
Harness::same(RiskTier::HighRisk, ActionCatalog::tier('delete_note'), 'delete_* is high-risk');
Harness::same(RiskTier::HighRisk, ActionCatalog::tier('delete_something_new'), 'including a delete we have not thought of');
Harness::same(false, ActionCatalog::exists('wire_money'), 'anything outside the list does not exist');

Harness::same(true, RiskTier::HighRisk->requiresScreenTap(), 'high-risk requires a screen tap');
Harness::same(false, RiskTier::HighRisk->allowsVoiceConfirmation(), 'a spoken yes is never sufficient for high-risk');
Harness::same(true, RiskTier::LowRiskWrite->isUndoable(), 'low-risk writes are undoable');
Harness::same(false, RiskTier::HighRisk->isUndoable(), 'high-risk writes are not');

Harness::group('§4 Intent validation');

$validator = new IntentValidator(0.70);
$context = new IntentContext('4471', "Henderson's Tahoe", 'Danny Cole');

$result = $validator->validate(
    new Intent('wire_money', new IntentTarget('context'), [], 0.99, 'send money to my cousin'),
    $context
);
Harness::same(ValidationResult::REJECTED, $result->status, 'an unknown action is rejected even at high confidence');
Harness::same('unknown_action', $result->reason, 'and logged with a reason');
Harness::contains("didn't catch that", $result->spoken, 'the spoken reply is the flat one from the spec');

$result = $validator->validate(
    new Intent('get_order', new IntentTarget('ro_number', '4471'), [], 0.4, 'pull up forty four seventy one'),
    $context
);
Harness::same(ValidationResult::CLARIFY, $result->status, 'below the threshold we ask rather than act');
Harness::contains('4471', $result->spoken, 'and the question repeats what we think we heard');

$result = $validator->validate(
    new Intent('get_history', new IntentTarget('context'), ['service_category' => 'brakes', 'delete_everything' => true], 0.9, 'brake history'),
    $context
);
Harness::same(ValidationResult::ACCEPTED, $result->status, 'a good intent passes');
Harness::same(['service_category' => 'brakes'], $result->intent->params, 'undeclared params are dropped, not passed through');

$result = $validator->validate(
    new Intent('add_inspection_item', new IntentTarget('context'), ['field_key' => 'front_brakes'], 0.95, 'front pads four mil'),
    $context
);
Harness::same(ValidationResult::REJECTED, $result->status, 'an inspection item outside inspection mode is rejected');

$result = $validator->validate(
    new Intent('add_note', new IntentTarget('context'), ['body' => 'x'], 0.95, 'note'),
    new IntentContext()
);
Harness::same(ValidationResult::CLARIFY, $result->status, 'a note with no RO loaded asks which one');

$result = $validator->validate(
    new Intent('add_inspection_item', new IntentTarget('context'), ['condition' => 'purple'], 0.95, 'front pads purple'),
    new IntentContext('4471', null, null, true)
);
Harness::same('bad_condition', $result->reason, 'an invented condition colour is rejected');

Harness::group('§4 raw_transcript is never rewritten');

$intent = Intent::fromArray(
    ['action' => 'add_note', 'confidence' => 0.9, 'raw_transcript' => 'a tidied up version the model preferred'],
    'the actual words the tech said'
);
Harness::same('the actual words the tech said', $intent->rawTranscript, 'the transcript we were given wins over the model echo');

Harness::group('Provider seam');

$provider = new RuleBasedProvider();
$intent = $provider->parse('when did we last do brakes on this one', $context);
Harness::same('get_history', $intent->action, 'the deterministic parser reads a history question');
Harness::same('brakes', $intent->stringParam('service_category'), 'and extracts the category');

$intent = $provider->parse('hey this is Russ, switch to my user', $context);
Harness::same('switch_user', $intent->action, 'and a voice user switch');
Harness::same('Russ', $intent->stringParam('user_name'), 'naming the user');

$intent = $provider->parse('grnnngh clatter clatter', $context);
Harness::ok($intent->confidence < 0.70, 'noise gets an honestly low confidence, not a confident guess');

Harness::same(
    ['action' => 'get_order'],
    HostedProvider::decodeJson("Sure!\n```json\n{\"action\": \"get_order\"}\n```"),
    'model JSON survives fences and preamble'
);
Harness::same(null, HostedProvider::decodeJson('I cannot help with that.'), 'prose with no JSON decodes to nothing');
