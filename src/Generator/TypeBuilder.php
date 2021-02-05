<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Generator;

use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\Parser;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use Murtukov\PHPCodeGenerator\ArrowFunction;
use Murtukov\PHPCodeGenerator\Closure;
use Murtukov\PHPCodeGenerator\Collection;
use Murtukov\PHPCodeGenerator\GeneratorInterface;
use Murtukov\PHPCodeGenerator\Instance;
use Murtukov\PHPCodeGenerator\Literal;
use Murtukov\PHPCodeGenerator\PhpFile;
use Murtukov\PHPCodeGenerator\Utils;
use Overblog\GraphQLBundle\Definition\ConfigProcessor;
use Overblog\GraphQLBundle\Definition\GraphQLServices;
use Overblog\GraphQLBundle\Definition\Type\CustomScalarType;
use Overblog\GraphQLBundle\Definition\Type\GeneratedTypeInterface;
use Overblog\GraphQLBundle\Error\ResolveErrors;
use Overblog\GraphQLBundle\ExpressionLanguage\Expression;
use Overblog\GraphQLBundle\Generator\Event\BuildEvent;
use Overblog\GraphQLBundle\Generator\Exception\GeneratorException;
use Overblog\GraphQLBundle\Resolver\ArgumentsMapper;
use Overblog\GraphQLBundle\Validator\Generator\TypeBuilder as ValidatorTypeBuilder;
use Symfony\Component\EventDispatcher\EventDispatcher;
use function array_map;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_callable;
use function is_string;
use function strtolower;

/**
 * Service that exposes a single method `build` called for each GraphQL
 * type config to build a PhpFile object.
 *
 * {@link https://github.com/murtukov/php-code-generator}
 *
 * It's responsible for building all GraphQL types (object, input-object,
 * interface, union, enum and custom-scalar).
 *
 * Every method with prefix 'build' has a render example in it's PHPDoc.
 */
class TypeBuilder
{
    public const CONSTRAINTS_NAMESPACE = 'Symfony\Component\Validator\Constraints';
    public const DOCBLOCK_TEXT = 'THIS FILE WAS GENERATED AND SHOULD NOT BE EDITED MANUALLY.';
    public const BUILT_IN_TYPES = [Type::STRING, Type::INT, Type::FLOAT, Type::BOOLEAN, Type::ID];

    protected const EXTENDS = [
        'object' => ObjectType::class,
        'input-object' => InputObjectType::class,
        'interface' => InterfaceType::class,
        'union' => UnionType::class,
        'enum' => EnumType::class,
        'custom-scalar' => CustomScalarType::class,
    ];

    protected PhpFile $file;
    protected string $namespace;
    protected array $config;
    protected string $type;
    protected string $currentField;
    protected string $gqlServices = '$'.TypeGenerator::GRAPHQL_SERVICES;
    protected EventDispatcher $eventDispatcher;
    protected BuildEvent $buildEvent;
    protected ArgumentsMapper $argMapper;

    public function __construct(
        string $namespace,
        EventDispatcher $eventDispatcher,
        ArgumentsMapper $argMapper,
        ValidatorTypeBuilder $valBuilder // temporary
    ) {
        $this->namespace = $namespace;
        $this->eventDispatcher = $eventDispatcher;
        $this->argMapper = $argMapper;
        $this->buildEvent = new BuildEvent();

        // TODO: move this to compiler pass
        $valBuilder->listen();
    }

    public function dispatch(string $eventName, $data)
    {
        $this->eventDispatcher->dispatch($this->buildEvent->setData($data), $eventName);
    }

    /**
     * @param array{
     *     name:          string,
     *     class_name:    string,
     *     fields:        array,
     *     description?:  string,
     *     interfaces?:   array,
     *     resolveType?:  string,
     *     validation?:   array,
     *     types?:        array,
     *     values?:       array,
     *     serialize?:    callable,
     *     parseValue?:   callable,
     *     parseLiteral?: callable,
     * } $config
     *
     * @throws GeneratorException
     */
    public function build(array $config, string $type): PhpFile
    {
        // This values should be accessible from every method
        $this->buildEvent->setRootConfig($config);
        $this->config = $config;
        $this->type = $type;

        $this->file = PhpFile::new()->setNamespace($this->namespace);

        $class = $this->file->createClass($config['class_name'])
            ->setFinal()
            ->setExtends(static::EXTENDS[$type])
            ->addImplements(GeneratedTypeInterface::class)
            ->addConst('NAME', $config['name'])
            ->setDocBlock(static::DOCBLOCK_TEXT);


        $class->emptyLine();

        $class->createConstructor()
            ->addArgument('configProcessor', ConfigProcessor::class)
            ->addArgument(TypeGenerator::GRAPHQL_SERVICES, GraphQLServices::class)
            ->append('$config = ', $this->buildConfig($config))
            ->emptyLine()
            ->append('parent::__construct($configProcessor->process($config))');

        $this->dispatch(BuildEvent::FILE_BUILD_END, $this->file);

        return $this->file;
    }

    /**
     * Converts a native GraphQL type string into the `webonyx/graphql-php`
     * type literal. References to user-defined types are converted into
     * TypeResovler method call and wrapped into a closure.
     *
     * Render examples:
     *
     *  -   "String"   -> Type::string()
     *  -   "String!"  -> Type::nonNull(Type::string())
     *  -   "[String!] -> Type::listOf(Type::nonNull(Type::string()))
     *  -   "[Post]"   -> Type::listOf($services->getType('Post'))
     *
     * @return GeneratorInterface|string
     */
    protected function buildType(string $typeDefinition)
    {
        $typeNode = Parser::parseType($typeDefinition);

        $isReference = false;
        $type = $this->wrapTypeRecursive($typeNode, $isReference);

        if ($isReference) {
            // References to other types should be wrapped in a closure
            // for performance reasons
            return ArrowFunction::new($type);
        }

        return $type;
    }

    /**
     * Used by {@see buildType}.
     *
     * @param mixed $typeNode
     *
     * @return Literal|string
     */
    protected function wrapTypeRecursive($typeNode, bool &$isReference)
    {
        switch ($typeNode->kind) {
            case NodeKind::NON_NULL_TYPE:
                $innerType = $this->wrapTypeRecursive($typeNode->type, $isReference);
                $type = Literal::new("Type::nonNull($innerType)");
                $this->file->addUse(Type::class);
                break;
            case NodeKind::LIST_TYPE:
                $innerType = $this->wrapTypeRecursive($typeNode->type, $isReference);
                $type = Literal::new("Type::listOf($innerType)");
                $this->file->addUse(Type::class);
                break;
            default: // NodeKind::NAMED_TYPE
                if (in_array($typeNode->name->value, static::BUILT_IN_TYPES)) {
                    $name = strtolower($typeNode->name->value);
                    $type = Literal::new("Type::$name()");
                    $this->file->addUse(Type::class);
                } else {
                    $name = $typeNode->name->value;
                    $type = "$this->gqlServices->getType('$name')";
                    $isReference = true;
                }
                break;
        }

        return $type;
    }

    /**
     * Builds a config array compatible with webonyx/graphql-php type system. The content
     * of the array depends on the GraphQL type that is currently being generated.
     *
     * Render example (object):
     *
     *      [
     *          'name' => self::NAME,
     *          'description' => 'Root query type',
     *          'fields' => fn() => [
     *              'posts' => {@see buildField},
     *              'users' => {@see buildField},
     *               ...
     *           ],
     *           'interfaces' => fn() => [
     *               $services->getType('PostInterface'),
     *               ...
     *           ],
     *           'resolveField' => {@see buildResolveField},
     *      ]
     *
     * Render example (input-object):
     *
     *      [
     *          'name' => self::NAME,
     *          'description' => 'Some description.',
     *          'validation' => {@see buildValidationRules}
     *          'fields' => fn() => [
     *              {@see buildField},
     *               ...
     *           ],
     *      ]
     *
     * Render example (interface)
     *
     *      [
     *          'name' => self::NAME,
     *          'description' => 'Some description.',
     *          'fields' => fn() => [
     *              {@see buildField},
     *               ...
     *           ],
     *          'resolveType' => {@see buildResolveType},
     *      ]
     *
     * Render example (union):
     *
     *      [
     *          'name' => self::NAME,
     *          'description' => 'Some description.',
     *          'types' => fn() => [
     *              $services->getType('Photo'),
     *              ...
     *          ],
     *          'resolveType' => {@see buildResolveType},
     *      ]
     *
     * Render example (custom-scalar):
     *
     *      [
     *          'name' => self::NAME,
     *          'description' => 'Some description'
     *          'serialize' => {@see buildScalarCallback},
     *          'parseValue' => {@see buildScalarCallback},
     *          'parseLiteral' => {@see buildScalarCallback},
     *      ]
     *
     * Render example (enum):
     *
     *      [
     *          'name' => self::NAME,
     *          'values' => [
     *              'PUBLISHED' => ['value' => 1],
     *              'DRAFT' => ['value' => 2],
     *              'STANDBY' => [
     *                  'value' => 3,
     *                  'description' => 'Waiting for validation',
     *              ],
     *              ...
     *          ],
     *      ]
     *
     * @throws GeneratorException
     */
    protected function buildConfig(array $config): Collection
    {
        // Convert to an object for a better readability
        $c = (object) $config;

        $configLoader = Collection::assoc();
        $configLoader->addItem('name', new Literal('self::NAME'));

        if (isset($c->description)) {
            $configLoader->addItem('description', $c->description);
        }

        // only by object, input-object and interface types
        if (!empty($c->fields)) {
            $configLoader->addItem('fields', ArrowFunction::new(
                Collection::map($c->fields, [$this, 'buildField'])
            ));
        }

        if (!empty($c->interfaces)) {
            $items = array_map(fn ($type) => "$this->gqlServices->getType('$type')", $c->interfaces);
            $configLoader->addItem('interfaces', ArrowFunction::new(Collection::numeric($items, true)));
        }

        if (!empty($c->types)) {
            $items = array_map(fn ($type) => "$this->gqlServices->getType('$type')", $c->types);
            $configLoader->addItem('types', ArrowFunction::new(Collection::numeric($items, true)));
        }

        if (isset($c->resolveType)) {
            $configLoader->addItem('resolveType', $this->buildResolveType($c->resolveType));
        }

        if (isset($c->resolveField)) {
            $configLoader->addItem('resolveField', $this->buildResolve($c->resolveField));
        }

        // only by enum types
        if (isset($c->values)) {
            $configLoader->addItem('values', Collection::assoc($c->values));
        }

        // only by custom-scalar types
        if ('custom-scalar' === $this->type) {
            if (isset($c->scalarType)) {
                $configLoader->addItem('scalarType', new Literal((string) $c->scalarType));
            }

            if (isset($c->serialize)) {
                $configLoader->addItem('serialize', $this->buildScalarCallback($c->serialize, 'serialize'));
            }

            if (isset($c->parseValue)) {
                $configLoader->addItem('parseValue', $this->buildScalarCallback($c->parseValue, 'parseValue'));
            }

            if (isset($c->parseLiteral)) {
                $configLoader->addItem('parseLiteral', $this->buildScalarCallback($c->parseLiteral, 'parseLiteral'));
            }
        }

        $this->eventDispatcher->dispatch($this->buildEvent->setData($configLoader, $config), BuildEvent::CONFIG_BUILD_END);

        return $configLoader;
    }

    /**
     * Builds an arrow function that calls a static method.
     *
     * Render example:
     *
     *      fn() => MyClassName::myMethodName(...\func_get_args())
     *
     * @param callable $callback - a callable string or a callable array
     *
     * @throws GeneratorException
     *
     * @return ArrowFunction
     */
    protected function buildScalarCallback($callback, string $fieldName)
    {
        if (!is_callable($callback)) {
            throw new GeneratorException("Value of '$fieldName' is not callable.");
        }

        $closure = new ArrowFunction();

        if (!is_string($callback)) {
            [$class, $method] = $callback;
        } else {
            [$class, $method] = explode('::', $callback);
        }

        $className = Utils::resolveQualifier($class);

        if ($className === $this->config['class_name']) {
            // Create an alias if name of serializer is same as type name
            $className = 'Base'.$className;
            $this->file->addUse($class, $className);
        } else {
            $this->file->addUse($class);
        }

        $closure->setExpression(Literal::new("$className::$method(...\\func_get_args())"));

        return $closure;
    }

    /**
     * Builds a resolver closure that contains the compiled result of user-defined
     * expression and optionally the validation logic.
     *
     * Render example (no expression language):
     *
     *      function ($value, $args, $context, $info) use ($services) {
     *          return "Hello, World!";
     *      }
     *
     * Render example (with expression language):
     *
     *      function ($value, $args, $context, $info) use ($services) {
     *          return $services->mutation("my_resolver", $args);
     *      }
     *
     * Render example (with validation):
     *
     *      function ($value, $args, $context, $info) use ($services) {
     *          $validator = $services->createInputValidator(...func_get_args());
     *          return $services->mutation("create_post", $validator]);
     *      }
     *
     * Render example (with validation, but errors are injected into the user-defined resolver):
     * {@link https://github.com/overblog/GraphQLBundle/blob/master/docs/validation/index.md#injecting-errors}
     *
     *      function ($value, $args, $context, $info) use ($services) {
     *          $errors = new ResolveErrors();
     *          $validator = $services->createInputValidator(...func_get_args());
     *
     *          $errors->setValidationErrors($validator->validate(null, false))
     *
     *          return $services->mutation("create_post", $errors);
     *      }
     *
     * @param mixed $resolve
     *
     * @throws GeneratorException
     *
     * @return GeneratorInterface|string
     */
    protected function buildResolve($resolve, ?array $groups = null)
    {
        if (is_callable($resolve) && is_array($resolve)) {
            return Collection::numeric($resolve);
        }

        // TODO: before creating an input validator, check if any validation rules are defined
        if ($resolve instanceof Expression) {
            $closure = Closure::new()
                ->addArguments('value', 'args', 'context', 'info')
                ->bindVar(TypeGenerator::GRAPHQL_SERVICES);

            $injectValidator = $resolve->containsVar('validator');

            if ($this->configContainsValidation()) {
                $injectErrors = $resolve->containsVar('errors');

                if ($injectErrors) {
                    $closure->append('$errors = ', Instance::new(ResolveErrors::class));
                }

                $closure->append('$validator = ', "$this->gqlServices->createInputValidator(...func_get_args())");

                // If auto-validation on or errors are injected
                if (!$injectValidator || $injectErrors) {
                    if (!empty($groups)) {
                        $validationGroups = Collection::numeric($groups);
                    } else {
                        $validationGroups = 'null';
                    }

                    $closure->emptyLine();

                    if ($injectErrors) {
                        $closure->append('$errors->setValidationErrors($validator->validate(', $validationGroups, ', false))');
                    } else {
                        $closure->append('$validator->validate(', $validationGroups, ')');
                    }

                    $closure->emptyLine();
                }
            } elseif ($injectValidator) {
                throw new GeneratorException('Unable to inject an instance of the InputValidator. No validation constraints provided. Please remove the "validator" argument from the list of dependencies of your resolver or provide validation configs.');
            }

            $closure->append('return ', (string) $resolve);

            return $closure;
        }

        return ArrowFunction::new($resolve);
    }

    protected function buildResolver(string $link)
    {
        // Определить кол-во и порядок аргументов
        $args = $this->argMapper->print($link);

        return "\$service->callResolver($link, $args)";
    }

    /**
     * Render example:
     *
     *      [
     *          'type' => {@see buildType},
     *          'description' => 'Some description.',
     *          'deprecationReason' => 'This field will be removed soon.',
     *          'args' => fn() => [
     *              {@see buildArg},
     *              {@see buildArg},
     *               ...
     *           ],
     *          'resolve' => {@see buildResolve},
     *          'complexity' => {@see buildComplexity},
     *      ]
     *
     * @param array{
     *     type:              string,
     *     resolve?:          string,
     *     description?:      string,
     *     args?:             array,
     *     complexity?:       string,
     *     deprecatedReason?: string,
     *     validation?:       array,
     * } $fieldConfig
     *
     * @internal
     *
     * @throws GeneratorException
     *
     * @return GeneratorInterface|Collection|string
     */
    public function buildField(array $fieldConfig, string $fieldname)
    {
        $this->currentField = $fieldname;

        // Convert to object for better readability
        $c = (object) $fieldConfig;

        // If there is only 'type', use shorthand
        if (1 === count($fieldConfig) && isset($c->type)) {
            return $this->buildType($c->type);
        }

        $field = Collection::assoc()
            ->addItem('type', $this->buildType($c->type));

        // only for object types
        if (isset($c->resolve)) {
            if (isset($c->validation)) {
                $field->addItem('validation', $this->buildValidationRules($c->validation));
            }
            $field->addItem('resolve', $this->buildResolve($c->resolve, $fieldConfig['validationGroups'] ?? null));
        }

        if (isset($c->deprecationReason)) {
            $field->addItem('deprecationReason', $c->deprecationReason);
        }

        if (isset($c->description)) {
            $field->addItem('description', $c->description);
        }

        if (!empty($c->args)) {
            $field->addItem('args', Collection::map($c->args, [$this, 'buildArg'], false));
        }

        if (isset($c->complexity)) {
            $field->addItem('complexity', $this->buildComplexity($c->complexity));
        }

        if (isset($c->public)) {
            $field->addItem('public', $this->buildPublic($c->public));
        }

        if (isset($c->access)) {
            $field->addItem('access', $this->buildAccess($c->access));
        }

        if (isset($c->access) && $c->access instanceof Expression && $c->access->containsVar('object')) {
            $field->addItem('useStrictAccess', false);
        }

        if ('input-object' === $this->type && isset($c->validation)) {
            $field->addItem('validation', $this->buildValidationRules($c->validation));
        }

        return $field;
    }

    /**
     * Render example:
     * <code>
     *  [
     *      'name' => 'username',
     *      'type' => {@see buildType},
     *      'description' => 'Some fancy description.',
     *      'defaultValue' => 'admin',
     *  ]
     * </code>
     *
     * @param array{
     *     type: string,
     *     description?: string,
     *     defaultValue?: string
     * } $argConfig
     *
     * @internal
     *
     * @throws GeneratorException
     */
    public function buildArg(array $argConfig, string $argName): Collection
    {
        // Convert to object for better readability
        $c = (object) $argConfig;

        $arg = Collection::assoc()
            ->addItem('name', $argName)
            ->addItem('type', $this->buildType($c->type));

        if (isset($c->description)) {
            $arg->addIfNotEmpty('description', $c->description);
        }

        if (isset($c->defaultValue)) {
            $arg->addIfNotEmpty('defaultValue', $c->defaultValue);
        }

        if (!empty($c->validation)) {
            if (in_array($c->type, self::BUILT_IN_TYPES) && isset($c->validation['cascade'])) {
                throw new GeneratorException('Cascade validation cannot be applied to built-in types.');
            }

            $arg->addIfNotEmpty('validation', $this->buildValidationRules($c->validation));
        }

        return $arg;
    }

    /**
     * Builds a closure or an arrow function, depending on whether the `args` param is provided.
     *
     * Render example (closure):
     *
     *      function ($value, $arguments) use ($services) {
     *          $args = $services->get('argumentFactory')->create($arguments);
     *          return ($args['age'] + 5);
     *      }
     *
     * Render example (arrow function):
     *
     *      fn($childrenComplexity) => ($childrenComplexity + 20);
     *
     * @param mixed $complexity
     *
     * @return Closure|mixed
     */
    protected function buildComplexity($complexity)
    {
        if ($complexity instanceof Expression) {
            if ($complexity->containsVar('args')) {
                return Closure::new()
                    ->addArgument('childrenComplexity')
                    ->addArgument('arguments', '', [])
                    ->bindVar(TypeGenerator::GRAPHQL_SERVICES)
                    ->append('$args = ', "$this->gqlServices->get('argumentFactory')->create(\$arguments)")
                    ->append('return ', (string) $complexity)
                ;
            }

            $arrow = ArrowFunction::new($complexity);

            if ($complexity->containsVar('childrenComplexity')) {
                $arrow->addArgument('childrenComplexity');
            }

            return $arrow;
        }

        return new ArrowFunction(0);
    }

    /**
     * Builds an arrow function from a string with an expression prefix,
     * otherwise just returns the provided value back untouched.
     *
     * Render example (if expression):
     *
     *      fn($fieldName, $typeName = self::NAME) => ($fieldName == "name")
     *
     * @param mixed $public
     *
     * @return ArrowFunction|mixed
     */
    protected function buildPublic($public)
    {
        if ($public instanceof Expression) {
            $func = ArrowFunction::new(Literal::new((string) $public));

            if ($public->containsVar('fieldName')) {
                $func->addArgument('fieldName');
            }

            if ($public->containsVar('typeName')) {
                $func->addArgument('fieldName');
                $func->addArgument('typeName', '', new Literal('self::NAME'));
            }

            return $func;
        }

        return $public;
    }

    /**
     * Builds an arrow function from a string with an expression prefix,
     * otherwise just returns the provided value back untouched.
     *
     * Render example (if expression):
     *
     *      fn($value, $args, $context, $info, $object) => $services->get('private_service')->hasAccess()
     *
     * @param mixed $access
     *
     * @return ArrowFunction|mixed
     */
    protected function buildAccess($access)
    {
        if ($access instanceof Expression) {
            return ArrowFunction::new()
                ->addArguments('value', 'args', 'context', 'info', 'object')
                ->setExpression(Literal::new((string) $access));
        }

        return $access;
    }

    /**
     * Builds an arrow function from a string with an expression prefix,
     * otherwise just returns the provided value back untouched.
     *
     * Render example:
     *
     *      fn($value, $context, $info) => $services->getType($value)
     *
     * @param mixed $resolveType
     *
     * @return mixed|ArrowFunction
     */
    protected function buildResolveType($resolveType)
    {
        if ($resolveType instanceof Expression) {
            return ArrowFunction::new()
                ->addArguments('value', 'context', 'info')
                ->setExpression(Literal::new((string) $resolveType));
        }

        return $resolveType;
    }
}
