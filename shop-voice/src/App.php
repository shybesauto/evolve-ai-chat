<?php
declare(strict_types=1);

namespace ShopVoice;

use ShopVoice\Auth\AuthService;
use ShopVoice\Db\Db;
use ShopVoice\Diagrams\DiagramProvider;
use ShopVoice\Diagrams\UnavailableDiagramProvider;
use ShopVoice\History\KeywordServiceCategoryMatcher;
use ShopVoice\History\ModelServiceCategoryMatcher;
use ShopVoice\History\ServiceHistoryService;
use ShopVoice\Inspection\InspectionService;
use ShopVoice\Intent\IntentProvider;
use ShopVoice\Intent\IntentProviderFactory;
use ShopVoice\Intent\IntentValidator;
use ShopVoice\Notes\LocalNoteStore;
use ShopVoice\Notes\NoteService;
use ShopVoice\Notes\NoteStore;
use ShopVoice\Notes\ShopmonkeyNoteStore;
use ShopVoice\Resolver\RoResolver;
use ShopVoice\Shopmonkey\FixtureShopmonkeyGateway;
use ShopVoice\Shopmonkey\LiveShopmonkeyGateway;
use ShopVoice\Shopmonkey\ShopmonkeyGateway;
use ShopVoice\Support\Clock;
use ShopVoice\Support\Config;
use ShopVoice\Support\CurlHttpClient;
use ShopVoice\Support\Env;
use ShopVoice\Support\HttpClient;
use ShopVoice\Support\Logger;
use ShopVoice\Undo\UndoService;
use ShopVoice\Voice\VoiceService;

/**
 * Wiring.
 *
 * Every swappable seam in the spec is decided here and nowhere else: which
 * model vendor (§3), which Shopmonkey source, which note store (§2), which
 * diagram provider (§12). Reading this constructor tells you the whole
 * deployment posture of the system.
 */
final class App
{
    private ?Db $db = null;
    private ?Logger $logger = null;
    private ?ShopmonkeyGateway $gateway = null;
    private ?IntentProvider $provider = null;
    private ?NoteStore $noteStore = null;
    private ?NoteService $notes = null;
    private ?InspectionService $inspections = null;
    private ?UndoService $undo = null;
    private ?AuthService $auth = null;
    private ?VoiceService $voice = null;

    public function __construct(
        public readonly Config $config,
        private ?HttpClient $http = null,
        public readonly Clock $clock = new Clock(),
        private ?string $storageDir = null,
    ) {
        $this->http ??= new CurlHttpClient();
        $this->storageDir ??= dirname(__DIR__) . '/storage';
    }

    public static function boot(?string $envPath = null): self
    {
        // The .env lives outside the web root. Inside public_html a server
        // misconfiguration serves it as plain text and leaks the token (§3).
        $envPath ??= dirname(__DIR__) . '/.env';
        if (!Env::isLoaded()) {
            Env::load($envPath);
        }

        $config = Config::fromEnv();
        date_default_timezone_set($config->string('app.timezone', 'America/Detroit'));

        return new self($config);
    }

    public function db(): Db
    {
        return $this->db ??= new Db(
            $this->config->string('db.dsn', 'sqlite::memory:'),
            $this->config->string('db.user') ?: null,
            $this->config->string('db.pass') ?: null,
        );
    }

    public function logger(): Logger
    {
        return $this->logger ??= new Logger($this->storageDir . '/logs', $this->clock);
    }

    public function http(): HttpClient
    {
        return $this->http;
    }

    public function gateway(): ShopmonkeyGateway
    {
        if ($this->gateway !== null) {
            return $this->gateway;
        }

        $mode = strtolower($this->config->string('shopmonkey.mode', 'fixture'));
        $token = $this->config->string('shopmonkey.token');

        if ($mode === 'live' && $token !== '') {
            return $this->gateway = new LiveShopmonkeyGateway(
                $this->http,
                $token,
                $this->config->string('shopmonkey.base_url', 'https://api.shopmonkey.cloud/v3/'),
                $this->logger(),
            );
        }

        if ($mode === 'live') {
            // Falling back rather than throwing keeps a token-less deploy usable
            // instead of dark, and the log line says exactly what happened.
            $this->logger()->warn('shopmonkey.live_without_token', ['fallback' => 'fixture']);
        }

        return $this->gateway = new FixtureShopmonkeyGateway();
    }

    public function intentProvider(): IntentProvider
    {
        return $this->provider ??= IntentProviderFactory::make($this->config, $this->http, $this->logger());
    }

    public function noteStore(): NoteStore
    {
        if ($this->noteStore !== null) {
            return $this->noteStore;
        }

        $configured = strtolower($this->config->string('notes.store', 'local'));
        $token = $this->config->string('shopmonkey.token');

        if ($configured === 'shopmonkey' && $token !== '') {
            // Still locked internally until config/shopmonkey_endpoints.php says
            // note.create is verified — see §2 and ShopmonkeyNoteStore.
            return $this->noteStore = new ShopmonkeyNoteStore(
                $this->http,
                $token,
                $this->config->string('shopmonkey.base_url', 'https://api.shopmonkey.cloud/v3/'),
                $this->logger(),
            );
        }

        return $this->noteStore = new LocalNoteStore();
    }

    public function auth(): AuthService
    {
        return $this->auth ??= new AuthService(
            $this->db(),
            $this->gateway(),
            $this->logger(),
            $this->clock,
            $this->config->int('session.idle_minutes', 720),
        );
    }

    public function notes(): NoteService
    {
        return $this->notes ??= new NoteService(
            $this->db(),
            $this->noteStore(),
            $this->intentProvider(),
            $this->logger(),
            $this->clock,
        );
    }

    public function inspections(): InspectionService
    {
        return $this->inspections ??= new InspectionService(
            $this->db(),
            $this->intentProvider(),
            $this->logger(),
            $this->clock,
            $this->config->int('inspection.idle_seconds', 300),
        );
    }

    public function undo(): UndoService
    {
        if ($this->undo !== null) {
            return $this->undo;
        }

        $undo = new UndoService(
            $this->db(),
            $this->logger(),
            $this->clock,
            $this->config->int('undo.window_seconds', 30),
        );

        // Reversers are registered here so UndoService stays ignorant of what it
        // is reversing — new undoable write types plug in without touching it.
        $undo->register('note', fn (string $id): bool => $this->notes()->undo($id));
        $undo->register('inspection_item', fn (string $id): bool => $this->inspections()->undoItem($id));

        return $this->undo = $undo;
    }

    public function resolver(): RoResolver
    {
        return new RoResolver($this->gateway());
    }

    public function history(): ServiceHistoryService
    {
        $keywords = new KeywordServiceCategoryMatcher();

        // Semantic matching via the model, with the keyword table underneath it
        // so a throttled free tier degrades the answer instead of removing it.
        $matcher = new ModelServiceCategoryMatcher($this->intentProvider(), $keywords, $this->logger());

        return new ServiceHistoryService($this->gateway(), $matcher, $this->clock);
    }

    public function diagrams(): DiagramProvider
    {
        // Build step 6. Deliberately last: most brittle piece in the system (§12).
        return new UnavailableDiagramProvider();
    }

    public function voice(): VoiceService
    {
        return $this->voice ??= new VoiceService(
            $this->db(),
            $this->auth(),
            $this->intentProvider(),
            new IntentValidator($this->config->float('intent.confidence_threshold', 0.70)),
            $this->gateway(),
            $this->resolver(),
            $this->history(),
            $this->notes(),
            $this->inspections(),
            $this->undo(),
            $this->diagrams(),
            $this->logger(),
            $this->clock,
        );
    }

    /** One line summarising which way every seam is currently thrown. */
    public function posture(): array
    {
        return [
            'env' => $this->config->string('app.env', 'production'),
            'shopmonkey' => $this->gateway()->describe(),
            'intent_provider' => $this->intentProvider()->name(),
            'intent_model' => $this->intentProvider()->model(),
            'note_store' => $this->noteStore()->describe(),
            'diagrams' => $this->diagrams()->describe(),
            'undo_window_seconds' => $this->config->int('undo.window_seconds', 30),
            'confidence_threshold' => $this->config->float('intent.confidence_threshold', 0.70),
        ];
    }
}
