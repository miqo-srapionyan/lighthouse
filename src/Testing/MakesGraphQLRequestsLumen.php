<?php

namespace Nuwave\Lighthouse\Testing;

use GraphQL\Type\Introspection;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Arr;
use Nuwave\Lighthouse\Support\Contracts\CanStreamResponse;
use Nuwave\Lighthouse\Support\Http\Responses\MemoryStream;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Testing helpers for making requests to the GraphQL endpoint.
 *
 * @mixin \Laravel\Lumen\Testing\Concerns\MakesHttpRequests
 */
trait MakesGraphQLRequestsLumen
{
    /**
     * Stores the result of the introspection query.
     *
     * On the first call to introspect() this property is set to
     * cache the result, as introspection is quite expensive.
     *
     * @var \Illuminate\Http\Response
     */
    protected $introspectionResult;

    /**
     * Used to test deferred queries.
     *
     * @var \Nuwave\Lighthouse\Support\Http\Responses\MemoryStream
     */
    protected $deferStream;

    /**
     * Execute a query as if it was sent as a request to the server.
     *
     * @param  string  $query  The GraphQL query to send
     * @param  array<string, mixed>  $variables  The variables to include in the query
     * @param  array<string, mixed>  $extraParams  Extra parameters to add to the JSON payload
     * @param  array<string, mixed>  $headers  HTTP headers to pass to the POST request
     */
    protected function graphQL(
        string $query,
        array $variables = [],
        array $extraParams = [],
        array $headers = []
    ): self {
        $params = ['query' => $query];

        if ([] !== $variables) {
            $params += ['variables' => $variables];
        }

        $params += $extraParams;
        $this->postGraphQL($params);

        return $this;
    }

    /**
     * Execute a POST to the GraphQL endpoint.
     *
     * Use this over graphQL() when you need more control or want to
     * test how your server behaves on incorrect inputs.
     *
     * @param  array<mixed, mixed>  $data  JSON-serializable payload
     * @param  array<string, string>  $headers  HTTP headers to pass to the POST request
     */
    protected function postGraphQL(array $data, array $headers = []): self
    {
        $this->post(
            $this->graphQLEndpointUrl(),
            $data,
            $headers
        );

        return $this;
    }

    /**
     * Send a multipart form request to GraphQL.
     *
     * This is used for file uploads conforming to the specification:
     * https://github.com/jaydenseric/graphql-multipart-request-spec
     *
     * @param  array<string, mixed>|array<int, array<string, mixed>>  $operations
     * @param  array<array<int, string>>  $map
     * @param  array<\Illuminate\Http\Testing\File>|array<array<mixed>>  $files
     * @param  array<string, string>  $headers  Will be merged with Content-Type: multipart/form-data
     *
     * @return $this
     */
    protected function multipartGraphQL(
        array $operations,
        array $map,
        array $files,
        array $headers = []
    ): self {
        $parameters = [
            'operations' => \Safe\json_encode($operations),
            'map' => \Safe\json_encode($map),
        ];

        $this->call(
            'POST',
            $this->graphQLEndpointUrl(),
            $parameters,
            [],
            $files,
            $this->transformHeadersToServerVars(array_merge(
                [
                    'Content-Type' => 'multipart/form-data',
                ],
                $headers
            ))
        );

        return $this;
    }

    /**
     * Execute the introspection query on the GraphQL server.
     */
    protected function introspect(): self
    {
        if (! isset($this->introspectionResult)) {
            $this->graphQL(Introspection::getIntrospectionQuery());
            $this->introspectionResult = $this->response;
        }

        return $this;
    }

    /**
     * Run introspection and return a type by name, if present.
     *
     * @return array<string, mixed>|null
     */
    protected function introspectType(string $name): ?array
    {
        return $this->introspectByName('data.__schema.types', $name);
    }

    /**
     * Run introspection and return a directive by name, if present.
     *
     * @return array<string, mixed>|null
     */
    protected function introspectDirective(string $name): ?array
    {
        return $this->introspectByName('data.__schema.directives', $name);
    }

    /**
     * Run introspection and return a result from the given path by name, if present.
     *
     * @return array<string, mixed>|null
     */
    protected function introspectByName(string $path, string $name): ?array
    {
        $this->introspect();
        assert($this->introspectionResult instanceof TestResponse);

        $content = $this->introspectionResult->getContent();
        assert(is_string($content));

        $results = data_get(
            \Safe\json_decode($content, true),
            $path
        );

        return Arr::first(
            $results,
            static function (array $result) use ($name): bool {
                return $result['name'] === $name;
            }
        );
    }

    /**
     * Return the full URL to the GraphQL endpoint.
     */
    protected function graphQLEndpointUrl(): string
    {
        $config = Container::getInstance()->make(ConfigRepository::class);
        assert($config instanceof ConfigRepository);

        return route($config->get('lighthouse.route.name'));
    }

    /**
     * Send the query and capture all chunks of the streamed response.
     *
     * @param  string  $query  The GraphQL query to send
     * @param  array<string, mixed>  $variables  The variables to include in the query
     * @param  array<string, mixed>  $extraParams  Extra parameters to add to the HTTP payload
     * @param  array<string, mixed>  $headers  HTTP headers to pass to the POST request
     *
     * @return array<int, mixed> The chunked results
     */
    protected function streamGraphQL(
        string $query,
        array $variables = [],
        array $extraParams = [],
        array $headers = []
    ): array {
        if (! isset($this->deferStream)) {
            $this->setUpDeferStream();
        }

        $response = $this->graphQL($query, $variables, $extraParams, $headers);

        // @phpstan-ignore-next-line can be true
        if (! $response->response instanceof StreamedResponse) {
            Assert::fail('Expected the response to be a streamed response but got a regular response.');
        }

        // @phpstan-ignore-next-line not always unreachable
        $response->response->send();

        return $this->deferStream->chunks;
    }

    /**
     * Set up the stream to make queries with `@defer`.
     */
    protected function setUpDeferStream(): void
    {
        $this->deferStream = new MemoryStream();

        Container::getInstance()->singleton(CanStreamResponse::class, function (): MemoryStream {
            assert($this->deferStream instanceof MemoryStream);

            return $this->deferStream;
        });
    }

    protected function rethrowGraphQLErrors(): void
    {
        $config = Container::getInstance()->make(ConfigRepository::class);
        assert($config instanceof ConfigRepository);

        $config->set('lighthouse.error_handlers', [RethrowingErrorHandler::class]);
    }
}
