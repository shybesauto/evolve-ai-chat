<?php
declare(strict_types=1);

namespace ShopVoice\Intent;

/**
 * The swappable model seam (§3).
 *
 * Same pattern as ShopCore's VehicleDataProvider: one adapter per vendor,
 * chosen by config. Swapping a free tier for a paid key is a config change,
 * never a rewrite — which is the entire reason a free tier is safe to start on.
 *
 * All four model-facing jobs live behind this one interface because they all go
 * to the same vendor and swap together:
 *   - parse()                 speech -> structured intent
 *   - matchServiceCategory()  "brakes" -> which of these service names count
 *   - draftCustomerNote()     tech's words -> plain-language customer version
 *   - slotInspectionItem()    a spoken finding -> the right sheet field
 *
 * Implementations must not talk to Shopmonkey. The model never touches it (§4).
 */
interface IntentProvider
{
    /** Vendor name, for logs and the intent envelope. */
    public function name(): string;

    public function model(): string;

    /**
     * Turn one utterance into a candidate intent. The result is a *candidate*:
     * it is not trusted until IntentValidator has passed it.
     */
    public function parse(string $transcript, IntentContext $context): Intent;

    /**
     * @param list<string> $serviceNames
     * @return list<string> the subset belonging to $category
     */
    public function matchServiceCategory(string $category, array $serviceNames): array;

    /**
     * Plain-language version of a dictated diagnosis, for the customer-facing
     * draft. Never posted automatically — see §6.
     */
    public function draftCustomerNote(string $rawTranscript, ?string $vehicleDescription = null): string;

    /**
     * Slot a spoken finding into an inspection sheet field. Findings arrive in
     * whatever order the tech walks the car (§8).
     *
     * @param list<array{field_key:string,label:string,unit:?string}> $fields
     * @return array{field_key:?string,value:?string,condition:?string,confidence:float}
     */
    public function slotInspectionItem(string $transcript, array $fields): array;
}
