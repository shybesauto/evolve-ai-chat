<?php
declare(strict_types=1);

namespace ShopVoice\History;

/**
 * Decides which of a vehicle's past service names belong to a spoken category.
 *
 * Naming is inconsistent in real data — "brakes" may be written *front pads and
 * rotors*, *brake job*, or *BR-FRT* — so this is a semantic question, not a
 * string comparison (§5).
 */
interface ServiceCategoryMatcher
{
    /**
     * @param list<string> $serviceNames candidate names from the vehicle's closed orders
     * @return list<string> the subset belonging to $category
     */
    public function match(string $category, array $serviceNames): array;

    /** A speakable label for the category: "brakes" -> "Front brakes" reads badly, "Brakes" reads fine. */
    public function label(string $category): string;
}
