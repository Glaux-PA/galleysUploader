<?php

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
    class GenericPlugin
    {
        public bool $enabled = false;
        public string $pluginCategory = 'generic';

        public function getActions($request, $actionArgs): array
        {
            return ['parentAction'];
        }

        public function getEnabled($contextId = null): bool
        {
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

    final class PluginTestRequest
    {
        private PluginTestRouter $router;
        private string $verb;

        public function __construct(PluginTestRouter $router, string $verb = 'upload')
        {
            $this->router = $router;
            $this->verb = $verb;
        }

        public function getRouter(): PluginTestRouter
        {
            return $this->router;
        }

        public function getUserVar(string $name)
        {
            return $name === 'verb' ? $this->verb : null;
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

    $plugin = new $class();
    $router = new PluginTestRouter();
    $request = new PluginTestRequest($router);

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
    foreach (['chapterform::display', 'chapterform::execute', 'Hook::add', 'PluginRegistry'] as $forbiddenPart) {
        if (stripos($pluginSource, $forbiddenPart) !== false) {
            throw new \RuntimeException('Chapter hooks or category jumping were activated: ' . $forbiddenPart);
        }
    }

    echo 'PublicationFormatsUploaderPlugin focused tests passed.' . PHP_EOL;
}
