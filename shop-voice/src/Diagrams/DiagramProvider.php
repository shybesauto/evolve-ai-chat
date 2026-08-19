<?php
declare(strict_types=1);

namespace ShopVoice\Diagrams;

/**
 * Wiring diagrams and procedures (§12).
 *
 * Shopmonkey's built-in diagrams are not exposed on their public API — that
 * content lives in their app UI — so this falls back to the shop's existing
 * ALLDATA and Identifix subscriptions through a server-side headless browser
 * session, deep-linking the tech to the right page.
 *
 * The tech gets ALLDATA's own viewer, which keeps zoom, pan and full page
 * context. That matters when a circuit runs across three diagrams. We do not
 * parse or re-render their pages: that breaks on every redesign.
 *
 * Scoping to a circuit is explicitly a non-goal for v1 — the assistant lands
 * him on the right diagram and he scans it himself.
 */
interface DiagramProvider
{
    /**
     * @return array{available:bool,url:?string,spoken:string,detail:?string}
     */
    public function locate(
        ?int $year,
        ?string $make,
        ?string $model,
        ?string $engine,
        string $system,
        ?string $component = null,
    ): array;

    public function describe(): string;
}
