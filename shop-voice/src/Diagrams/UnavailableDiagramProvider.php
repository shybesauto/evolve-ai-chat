<?php
declare(strict_types=1);

namespace ShopVoice\Diagrams;

/**
 * What ships until build step 6.
 *
 * §13 puts diagrams last on purpose: it is the most brittle piece in the
 * system, depending on a headless session against a third party's UI that can
 * change without notice. Building it before the rest is proven would mean
 * debugging the shakiest component while the foundations are still moving.
 *
 * Until then this answers honestly rather than failing silently — the same rule
 * §10 applies to offline lookups. A tech who is told "not wired up yet" opens
 * ALLDATA on the shop computer; a tech who gets a spinner loses ten minutes.
 */
final class UnavailableDiagramProvider implements DiagramProvider
{
    public function locate(
        ?int $year,
        ?string $make,
        ?string $model,
        ?string $engine,
        string $system,
        ?string $component = null,
    ): array {
        $vehicle = trim(implode(' ', array_filter([$year, $make, $model])));

        return [
            'available' => false,
            'url' => null,
            'spoken' => 'Diagrams are not wired up yet — pull it up on ALLDATA.',
            'detail' => $vehicle !== ''
                ? sprintf('Would have searched ALLDATA for: %s, %s.', $vehicle, $system)
                : null,
        ];
    }

    public function describe(): string
    {
        return 'not implemented — build step 6 (§12)';
    }
}
