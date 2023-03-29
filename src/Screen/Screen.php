<?php

declare(strict_types=1);

namespace Orchid\Screen;

use Illuminate\Container\Container;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use Laravel\SerializableClosure\SerializableClosure;
use Orchid\Platform\Http\Controllers\Controller;
use Orchid\Screen\Layouts\Modal;
use Orchid\Screen\Resolvers\ScreenDependencyResolver;
use Orchid\Support\Facades\Dashboard;
use Throwable;

/**
 * Class Screen.
 *
 * This is the main class for creating screens in the Orchid. A screen is a web page
 * that displays content and allows for user interaction.
 */
abstract class Screen extends Controller
{
    use Commander;

    /**
     * The number of predefined arguments in the route.
     *
     * Example: dashboard/my-screen/{method?}
     */
    private const COUNT_ROUTE_VARIABLES = 1;

    /**
     * The base view that will be rendered.
     */
    protected function screenBaseView(): string
    {
        return 'platform::layouts.base';
    }

    /**
     * The name of the screen to be displayed in the header.
     */
    public function name(): ?string
    {
        return $this->name ?? null;
    }

    /**
     * A description of the screen to be displayed in the header.
     */
    public function description(): ?string
    {
        return $this->description ?? null;
    }

    /**
     * The permissions required to access this screen.
     */
    public function permission(): ?iterable
    {
        return isset($this->permission)
            ? Arr::wrap($this->permission)
            : null;
    }

    /**
     * @var Repository
     */
    private $source;

    /**
     * The command buttons for this screen.
     *
     * @return Action[]
     */
    public function commandBar()
    {
        return [];
    }

    /**
     * The layout for this screen, consisting of a collection of views.
     *
     * @return Layout[]
     */
    abstract public function layout(): iterable;

    /**
     * Builds the screen using the given data repository.
     *
     * @param \Orchid\Screen\Repository $repository
     *
     * @return View
     */
    public function build(Repository $repository)
    {
        return LayoutFactory::blank([
            $this->layout(),
        ])->build($repository);
    }

    /**
     * Builds the screen asynchronously using the given method and template slug.
     *
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \ReflectionException
     *
     * @return \Illuminate\Http\Response
     */
    public function asyncBuild(string $method, string $slug)
    {
        Dashboard::setCurrentScreen($this, true);

        abort_unless(method_exists($this, $method), 404, "Async method: {$method} not found");

        $parameters = request()->collect()->merge([
            'state'   => $this->extractState(),
        ])->toArray();

        $repository = $this->callMethod($method, $parameters);

        if (is_array($repository)) {
            $repository = new Repository($repository);
        }

        /** @var Layout $layout */
        $layout = collect($this->layout())
            ->map(fn ($layout) => is_object($layout) ? $layout : resolve($layout))
            ->map(fn (Layout $layout) => $layout->findBySlug($slug))
            ->filter()
            ->whenEmpty(function () use ($slug) {
                abort(404, "Async template: {$slug} not found");
            })
            ->first();

        $template = is_a($layout, Modal::class)
            ? $layout->currentAsync()->build($repository)
            : $layout->build($repository);

        $state = is_a($layout, Modal::class)
            ? null
            : $this->serializableState($repository);

        return response()->view('platform::turbo.stream', [
            'template' => $template,
            'state'    => $state,
            'target'   => $slug,
            'action'   => 'replace',
        ])
            ->header('Content-Type', 'text/vnd.turbo-stream.html');
    }

    /**
     * This method extracts the state from a request parameter.
     * If the '_state' parameter is missing, an empty Repository object is returned.
     * Otherwise, the state is extracted from the encrypted '_state' parameter, deserialized and returned.
     *
     * @throws \Psr\Container\ContainerExceptionInterface - If the container cannot provide the dependency injection for a class.
     * @throws \Psr\Container\NotFoundExceptionInterface  - If the container cannot find a required dependency injection for a class.
     *
     * @return \Orchid\Screen\Repository - The extracted state.
     */
    protected function extractState(): Repository
    {
        // Check if the '_state' parameter is missing
        if (request()->missing('_state')) {
            // Return an empty Repository object
            return new Repository();
        }

        // Extract the encrypted state from the '_state' parameter, and deserialize it
        $state = unserialize(Crypt::decryptString(request()->get('_state')));

        // Return the deserialized state
        return $state();
    }

    /**
     * @throws \Throwable
     *
     * @return Factory|\Illuminate\View\View
     */
    public function view(array $httpQueryArguments = [])
    {
        $repository = $this->buildQueryRepository($httpQueryArguments);

        return view($this->screenBaseView(), [
            'name'                    => $this->name(),
            'description'             => $this->description(),
            'commandBar'              => $this->buildCommandBar($repository),
            'layouts'                 => $this->build($repository),
            'formValidateMessage'     => $this->formValidateMessage(),
            'needPreventsAbandonment' => $this->needPreventsAbandonment(),
            'state'                   => $this->serializableState($repository),
        ]);
    }

    /**
     * @param $values
     *
     * @throws \Laravel\SerializableClosure\Exceptions\PhpVersionNotSupportedException
     *
     * @return string
     */
    protected function serializableState($values): string
    {
        $state = serialize(new SerializableClosure(fn () => $values));

        return Crypt::encryptString($state);
    }

    /**
     * @return \Orchid\Screen\Repository
     */
    protected function buildQueryRepository(array $httpQueryArguments = []): Repository
    {
        $query = $this->callMethod('query', $httpQueryArguments);

        $this->fillPublicProperty($query);

        return new Repository($query);
    }

    protected function fillPublicProperty(iterable $query): void
    {
        $reflections = (new \ReflectionClass($this))->getProperties(\ReflectionProperty::IS_PUBLIC);

        $publicProperty = collect($reflections)
            ->map(fn (\ReflectionProperty $property) => $property->getName());

        collect($query)->only($publicProperty)->each(function ($value, $key) {
            $this->$key = $value;
        });
    }

    /**
     * Response or HTTP code that will be returned if user does not have access to screen.
     *
     * @return int | \Symfony\Component\HttpFoundation\Response
     */
    public static function unaccessed()
    {
        return Response::HTTP_FORBIDDEN;
    }

    /**
     * @param mixed ...$parameters
     *
     * @throws Throwable
     *
     * @return Factory|View|\Illuminate\View\View|mixed
     */
    public function handle(Request $request, ...$parameters)
    {
        Dashboard::setCurrentScreen($this);

        abort_unless($this->checkAccess($request), static::unaccessed());

        if ($request->isMethod('GET')) {
            return $this->redirectOnGetMethodCallOrShowView($parameters);
        }

        $method = Route::current()->parameter('method', Arr::last($parameters));

        $prepare = collect($parameters)
            ->merge($request->query())
            ->diffAssoc($method)
            ->all();

        return $this->callMethod($method, $prepare) ?? back();
    }

    /**
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \ReflectionException
     */
    protected function resolveDependencies(string $method, array $httpQueryArguments = []): array
    {
        return app()->make(ScreenDependencyResolver::class)->resolveScreen($this, $method, $httpQueryArguments);
    }

    /**
     * Determine if the user is authorized and has the required rights to complete this request.
     */
    protected function checkAccess(Request $request): bool
    {
        $user = $request->user();

        if ($user === null) {
            return true;
        }

        return $user->hasAnyAccess($this->permission());
    }

    /**
     * This method returns a localized string message indicating that the user should check the entered data,
     * and that it may be necessary to specify the data in other languages.
     */
    public function formValidateMessage(): string
    {
        return __('Please check the entered data, it may be necessary to specify in other languages.');
    }

    /**
     * The boolean value returned is true, indicating that the form is preventing abandonment.
     */
    public function needPreventsAbandonment(): bool
    {
        return true;
    }

    /**
     * Defines the URL to represent
     * the page based on the calculation of link arguments.
     *
     *
     * @throws \ReflectionException
     * @throws \Throwable
     *
     * @return Factory|RedirectResponse|\Illuminate\View\View
     */
    protected function redirectOnGetMethodCallOrShowView(array $httpQueryArguments)
    {
        $expectedArg = count(Route::current()->getCompiled()->getVariables()) - self::COUNT_ROUTE_VARIABLES;
        $realArg = count($httpQueryArguments);

        if ($realArg <= $expectedArg) {
            return $this->view($httpQueryArguments);
        }

        array_pop($httpQueryArguments);

        return redirect()->action([static::class, 'handle'], $httpQueryArguments);
    }

    /**
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \ReflectionException
     *
     * @return mixed
     */
    private function callMethod(string $method, array $parameters = [])
    {
        return call_user_func_array([$this, $method],
            $this->resolveDependencies($method, $parameters)
        );
    }

    /**
     * Get can transfer to the screen only
     * user-created methods available in it.
     */
    public static function getAvailableMethods(): Collection
    {
        $class = (new \ReflectionClass(static::class))
            ->getMethods(\ReflectionMethod::IS_PUBLIC);

        return collect($class)
            ->mapWithKeys(fn (\ReflectionMethod $method) => [$method->name => $method])
            ->except(get_class_methods(Screen::class))
            ->except(['query'])
            /*
             * Route filtering requires at least one element to be present.
             * We set __invoke by default, since it must be public.
             */
            ->whenEmpty(fn () => collect('__invoke'))
            ->keys();
    }
}
