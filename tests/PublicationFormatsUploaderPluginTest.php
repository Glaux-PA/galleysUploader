<?php

namespace APP\core {
    class Application
    {
        private static $application;
        private $request;

        public static function setRequest($request): void
        {
            self::$application = new self();
            self::$application->request = $request;
        }

        public static function get(): self
        {
            return self::$application;
        }

        public function getRequest()
        {
            return $this->request;
        }
    }
}

namespace PKP\core {
    class JSONMessage
    {
        public bool $status;
        public $content;

        public function __construct(bool $status, $content = '')
        {
            $this->status = $status;
            $this->content = $content;
        }
    }
}

namespace PKP\linkAction\request {
    class AjaxModal
    {
        public string $url;
        public ?string $title;

        public function __construct(string $url, ?string $title = null)
        {
            $this->url = $url;
            $this->title = $title;
        }
    }
}

namespace PKP\linkAction {
    class LinkAction
    {
        public string $id;
        public $request;
        public string $title;

        public function __construct(string $id, $request, string $title)
        {
            $this->id = $id;
            $this->request = $request;
            $this->title = $title;
        }
    }
}

namespace PKP\plugins {
    class Hook
    {
        public const CONTINUE = false;
        public static array $callbacks = [];

        public static function add(string $hookName, callable $callback): void
        {
            self::$callbacks[$hookName][] = $callback;
        }

        public static function reset(): void
        {
            self::$callbacks = [];
        }
    }

    class GenericPlugin
    {
        public bool $enabled = false;
        public array $enabledByContext = [];
        public string $pluginCategory = 'generic';
        public array $registerCalls = [];

        public function register($category, $path, $mainContextId = null): bool
        {
            $this->pluginCategory = $category;
            $this->registerCalls[] = compact('category', 'path', 'mainContextId');
            return true;
        }

        public function getActions($request, $actionArgs): array
        {
            return ['parentAction'];
        }

        public function getEnabled($contextId = null): bool
        {
            if ($contextId !== null && array_key_exists($contextId, $this->enabledByContext)) {
                return $this->enabledByContext[$contextId];
            }

            return $this->enabled;
        }

        public function getCategory(): string
        {
            return $this->pluginCategory;
        }

        public function getName(): string
        {
            $parts = explode('\\', get_class($this));
            return strtolower(end($parts));
        }

        public function manage($args, $request)
        {
            throw new \RuntimeException('Unexpected parent manage call.');
        }
    }
}

namespace {
    function __($key)
    {
        return $key;
    }

    require_once dirname(__DIR__) . '/PublicationFormatsUploaderPlugin.php';

    final class PluginTestRouter
    {
        public array $call;

        public function url($request, $context, $page, $op, $path, $params): string
        {
            $this->call = compact('request', 'context', 'page', 'op', 'path', 'params');
            return '/component/manage';
        }
    }

    final class PluginTestContext
    {
        private int $id;

        public function __construct(int $id)
        {
            $this->id = $id;
        }

        public function getId(): int
        {
            return $this->id;
        }
    }

    final class PluginTestRequest
    {
        private PluginTestRouter $router;
        private string $verb;
        private ?PluginTestContext $context;

        public function __construct(
            PluginTestRouter $router,
            string $verb = 'upload',
            ?PluginTestContext $context = null
        )
        {
            $this->router = $router;
            $this->verb = $verb;
            $this->context = $context;
        }

        public function getRouter(): PluginTestRouter
        {
            return $this->router;
        }

        public function getUserVar(string $name)
        {
            return $name === 'verb' ? $this->verb : null;
        }

        public function getContext(): ?PluginTestContext
        {
            return $this->context;
        }

        public function setContext(?PluginTestContext $context): void
        {
            $this->context = $context;
        }
    }

    final class SpyChapterFileFilter extends ChapterFileFilter
    {
        public array $displayCalls = [];
        public array $executeCalls = [];

        public function filterChapterFileOptions(string $hookName, array $args): bool
        {
            $this->displayCalls[] = compact('hookName', 'args');
            return true;
        }

        public function preserveHiddenDependentAssignments(string $hookName, array $args): bool
        {
            $this->executeCalls[] = compact('hookName', 'args');
            return true;
        }
    }

    function assertPluginShellSame($expected, $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException(
                $message . PHP_EOL . 'Expected: ' . var_export($expected, true)
                . PHP_EOL . 'Actual: ' . var_export($actual, true)
            );
        }
    }

    $class = \APP\plugins\generic\publicationFormatsUploader\PublicationFormatsUploaderPlugin::class;
    $reflection = new \ReflectionClass($class);
    assertPluginShellSame(
        'APP\\plugins\\generic\\publicationFormatsUploader',
        $reflection->getNamespaceName(),
        'The plugin must use the OMP generic-plugin namespace.'
    );
    assertPluginShellSame(
        \PKP\plugins\GenericPlugin::class,
        $reflection->getParentClass()->getName(),
        'The plugin must extend GenericPlugin.'
    );

    $router = new PluginTestRouter();
    $request = new PluginTestRequest($router, 'upload', new PluginTestContext(17));
    \APP\core\Application::setRequest($request);

    \PKP\plugins\Hook::reset();
    $disabledPlugin = new $class();
    $disabledPlugin->enabledByContext[17] = false;
    assertPluginShellSame(
        true,
        $disabledPlugin->register('generic', 'plugins/generic/publicationFormatsUploader', 17),
        'The plugin must preserve successful parent registration while disabled.'
    );
    assertPluginShellSame(
        [],
        \PKP\plugins\Hook::$callbacks,
        'Chapter hooks must not be registered when the plugin is disabled for the press.'
    );

    \PKP\plugins\Hook::reset();
    $filteringPlugin = new $class();
    $filteringPlugin->enabledByContext[17] = true;
    $filteringPlugin->register('generic', 'plugins/generic/publicationFormatsUploader', 17);
    assertPluginShellSame(
        ['chapterform::display', 'chapterform::execute'],
        array_keys(\PKP\plugins\Hook::$callbacks),
        'The enabled plugin must register both chapter-form hooks in its generic registration lifecycle.'
    );
    assertPluginShellSame(
        'filterChapterFileOptions',
        \PKP\plugins\Hook::$callbacks['chapterform::display'][0][1],
        'The display hook must target the plugin delegation callback.'
    );
    assertPluginShellSame(
        'preserveHiddenDependentAssignments',
        \PKP\plugins\Hook::$callbacks['chapterform::execute'][0][1],
        'The execute hook must target the plugin delegation callback.'
    );

    $filterSpy = new SpyChapterFileFilter();
    $filterProperty = $reflection->getProperty('chapterFileFilter');
    $filterProperty->setAccessible(true);
    $filterProperty->setValue($filteringPlugin, $filterSpy);
    $displayArgs = [(object) ['kind' => 'display-form']];
    $executeArgs = [(object) ['kind' => 'execute-form']];
    assertPluginShellSame(
        true,
        $filteringPlugin->filterChapterFileOptions('chapterform::display', $displayArgs),
        'The enabled display callback must return the filter delegate result.'
    );
    assertPluginShellSame(
        [['hookName' => 'chapterform::display', 'args' => $displayArgs]],
        $filterSpy->displayCalls,
        'The display callback must delegate the hook name and wrapped arguments unchanged.'
    );
    assertPluginShellSame(
        true,
        $filteringPlugin->preserveHiddenDependentAssignments('chapterform::execute', $executeArgs),
        'The enabled execute callback must return the filter delegate result.'
    );
    assertPluginShellSame(
        [['hookName' => 'chapterform::execute', 'args' => $executeArgs]],
        $filterSpy->executeCalls,
        'The execute callback must delegate the hook name and wrapped arguments unchanged.'
    );

    $filteringPlugin->enabledByContext[17] = false;
    assertPluginShellSame(
        \PKP\plugins\Hook::CONTINUE,
        $filteringPlugin->filterChapterFileOptions('chapterform::display', $displayArgs),
        'A registered display callback must have no effect after the plugin is disabled.'
    );
    assertPluginShellSame(
        \PKP\plugins\Hook::CONTINUE,
        $filteringPlugin->preserveHiddenDependentAssignments('chapterform::execute', $executeArgs),
        'A registered execute callback must have no effect after the plugin is disabled.'
    );
    assertPluginShellSame(1, count($filterSpy->displayCalls), 'Disabled display must not reach the delegate.');
    assertPluginShellSame(1, count($filterSpy->executeCalls), 'Disabled execute must not reach the delegate.');

    $filteringPlugin->enabledByContext[17] = true;
    $request->setContext(null);
    assertPluginShellSame(
        \PKP\plugins\Hook::CONTINUE,
        $filteringPlugin->filterChapterFileOptions('chapterform::display', $displayArgs),
        'The callback must have no effect when the request has no current press.'
    );
    assertPluginShellSame(1, count($filterSpy->displayCalls), 'A contextless request must not reach the delegate.');
    $request->setContext(new PluginTestContext(17));

    $plugin = new $class();

    assertPluginShellSame(
        ['parentAction'],
        $plugin->getActions($request, []),
        'The upload action must be hidden while the plugin is disabled.'
    );

    $disabledResponse = $plugin->manage([], $request);
    assertPluginShellSame(false, $disabledResponse->status, 'Disabled manage requests must be rejected.');
    assertPluginShellSame(
        'plugins.generic.publicationFormatsUploader.error.pluginDisabled',
        $disabledResponse->content,
        'Disabled manage requests must return the plugin-disabled message.'
    );

    $plugin->enabled = true;
    $actions = $plugin->getActions($request, []);
    assertPluginShellSame(2, count($actions), 'The enabled plugin must prepend one upload action.');
    assertPluginShellSame('uploadPublicationFormats', $actions[0]->id, 'The upload action ID must be stable.');
    assertPluginShellSame('/component/manage', $actions[0]->request->url, 'The action must use the manage route.');
    assertPluginShellSame('manage', $router->call['op'], 'The component operation must be manage.');
    assertPluginShellSame(
        [
            'verb' => 'upload',
            'plugin' => 'publicationformatsuploaderplugin',
            'category' => 'generic',
        ],
        $router->call['params'],
        'The action must route through the generic plugin grid with the upload verb.'
    );

    $version = simplexml_load_file(dirname(__DIR__) . '/version.xml');
    assertPluginShellSame('plugins.generic', (string) $version->type, 'The manifest category must be generic.');
    assertPluginShellSame(
        'PublicationFormatsUploaderPlugin',
        (string) $version->class,
        'The manifest must name the OMP 3.5 plugin class.'
    );

    $template = file_get_contents(dirname(__DIR__) . '/templates/index.tpl');
    foreach (['ROUTE_COMPONENT', 'op="manage"', 'verb="uploadTemporaryFile"', 'verb="uploadFile"'] as $routePart) {
        if (!str_contains($template, $routePart)) {
            throw new \RuntimeException('Missing modal component route fragment: ' . $routePart);
        }
    }
    foreach (['plugin_url', 'layouts/backend.tpl'] as $removedShellPart) {
        if (str_contains($template, $removedShellPart)) {
            throw new \RuntimeException('The old full-page shell remains in the modal template: ' . $removedShellPart);
        }
    }

    $pluginSource = file_get_contents(dirname(__DIR__) . '/PublicationFormatsUploaderPlugin.php');
    foreach (['chapterform::display', 'chapterform::execute', 'Hook::add'] as $requiredPart) {
        if (stripos($pluginSource, $requiredPart) === false) {
            throw new \RuntimeException('Missing chapter hook integration: ' . $requiredPart);
        }
    }
    if (stripos($pluginSource, 'PluginRegistry') !== false) {
        throw new \RuntimeException('Plugin category jumping must not be introduced.');
    }

    echo 'PublicationFormatsUploaderPlugin focused tests passed.' . PHP_EOL;
}
